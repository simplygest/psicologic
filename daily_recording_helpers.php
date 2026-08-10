<?php

require_once __DIR__ . '/daily_helpers.php';

function daily_download_recording_to_file(string $url, string $target): void
{
    $handle = fopen($target, 'wb');
    if (!$handle) throw new RuntimeException('No se pudo preparar el archivo temporal de la grabación.');
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_FILE => $handle, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 900]);
    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    fclose($handle);
    if (!$ok || $status < 200 || $status >= 300 || !is_file($target) || filesize($target) < 1) {
        @unlink($target);
        throw new RuntimeException($error ?: 'Daily no permitió descargar la grabación.');
    }
}

function daily_sync_appointment_recordings($mysqli, array $appointment): array
{
    ensure_livekit_recording_schema($mysqli);
    ensure_patient_document_tables($mysqli);
    $tenantId = current_tenant_id();
    $appointmentId = (int) $appointment['id'];
    $patientId = (int) $appointment['user_id'];
    $professionalId = (int) ($appointment['professional_id'] ?? 0);
    $professionalSettings = cabinet_get_effective_professional_settings($mysqli, $professionalId);
    $configuredMode = (($professionalSettings['livekit_recording_mode'] ?? 'audio') === 'audio_video' && video_recording_video_plan_enabled($mysqli)) ? 'audio_video' : 'audio';
    $room = livekit_room_name($tenantId, $appointmentId);
    $response = daily_api_request('GET', 'recordings?room_name=' . rawurlencode($room) . '&limit=100');
    foreach ((array) ($response['data'] ?? []) as $remote) {
        $remoteId = trim((string) ($remote['id'] ?? ''));
        if ($remoteId === '') continue;
        $status = strtolower((string) ($remote['status'] ?? 'processing'));
        $remoteType = (string) ($remote['recording_type'] ?? $remote['type'] ?? '');
        $mode = $remoteType === 'cloud-audio-only' ? 'audio' : ($remoteType === 'cloud' ? 'audio_video' : $configuredMode);
        $durationSeconds = max(0, (int) ($remote['duration'] ?? 0));
        $started = !empty($remote['start_ts']) ? date('Y-m-d H:i:s', (int) $remote['start_ts']) : null;
        $stmt = $mysqli->prepare("INSERT INTO appointment_recordings (tenant_id,appointment_id,patient_id,professional_id,provider,room_name,egress_id,recording_mode,duration_seconds,status,started_at,created_by) VALUES (?,?,?,?,'daily',?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE duration_seconds=COALESCE(NULLIF(VALUES(duration_seconds),0),duration_seconds), status=IF(document_id IS NULL,VALUES(status),status), updated_at=CURRENT_TIMESTAMP");
        $createdBy = (int) ($_SESSION['user_id'] ?? 0);
        $localStatus = in_array($status, ['finished', 'ready', 'completed'], true) ? 'ready_remote' : $status;
        $stmt->bind_param('iiiisssissi', $tenantId, $appointmentId, $patientId, $professionalId, $room, $remoteId, $mode, $durationSeconds, $localStatus, $started, $createdBy);
        $stmt->execute();

        $lookup = $mysqli->prepare("SELECT id,document_id FROM appointment_recordings WHERE provider='daily' AND egress_id=? LIMIT 1");
        $lookup->bind_param('s', $remoteId); $lookup->execute(); $row = $lookup->get_result()->fetch_assoc();
        if (!$row || !in_array($status, ['finished', 'ready', 'completed'], true)) continue;
        if ((int) ($row['document_id'] ?? 0) > 0) {
            // A previous import may have succeeded while the remote cleanup failed.
            daily_api_request('DELETE', 'recordings/' . rawurlencode($remoteId));
            continue;
        }

        $access = daily_api_request('GET', 'recordings/' . rawurlencode($remoteId) . '/access-link');
        $url = (string) ($access['download_link'] ?? $access['link'] ?? '');
        if ($url === '') throw new RuntimeException('Daily no devolvió el enlace de descarga de una grabación.');
        $filename = 'videollamada-' . $appointmentId . '-' . preg_replace('/[^a-zA-Z0-9-]/', '', $remoteId) . '.mp4';
        $directory = app_tenant_protected_upload_dir('recordings');
        if (!app_ensure_dir($directory)) throw new RuntimeException('No se pudo preparar el almacenamiento de grabaciones.');
        $localPath = $directory . '/' . $filename;
        $temporary = tempnam(sys_get_temp_dir(), 'praxis-daily-');
        try {
            daily_download_recording_to_file($url, $temporary);
            $relative = app_tenant_protected_upload_relative_path('recordings', $filename);
            if (!praxis_storage_copy_local($temporary, $relative, $localPath, 'video/mp4')) throw new RuntimeException('No se pudo guardar la grabación.');
            $size = (int) filesize($localPath);
            $title = 'Grabación de videollamada - ' . date('d/m/Y H:i', strtotime(($appointment['appointment_date'] ?? '') . ' ' . ($appointment['appointment_time'] ?? '')));
            $type = 'recording'; $description = livekit_recording_mode_label($mode); $date = $appointment['appointment_date']; $visible = 0; $docStatus = 'completed';
            $doc = $mysqli->prepare("INSERT INTO patient_documents (tenant_id,patient_id,appointment_id,professional_id,document_type,title,description,document_date,file_path,original_file_name,file_size,mime_type,visible_to_patient,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,'video/mp4',?,?,?)");
            $doc->bind_param('iiiissssssiisi', $tenantId,$patientId,$appointmentId,$professionalId,$type,$title,$description,$date,$relative,$filename,$size,$visible,$docStatus,$createdBy);
            if (!$doc->execute()) throw new RuntimeException('No se pudo registrar la grabación en los documentos del paciente.');
            $documentId = (int) $mysqli->insert_id;
            if ($documentId <= 0) throw new RuntimeException('No se pudo identificar el documento creado para la grabación.');
            $update = $mysqli->prepare("UPDATE appointment_recordings SET document_id=?,status='ready',stopped_at=NOW() WHERE id=?");
            $recordingId = (int) $row['id']; $update->bind_param('ii',$documentId,$recordingId);
            if (!$update->execute()) throw new RuntimeException('No se pudo finalizar el registro local de la grabación.');
            daily_api_request('DELETE', 'recordings/' . rawurlencode($remoteId));
        } finally { if (is_file($temporary)) @unlink($temporary); }
    }
    $stmt = $mysqli->prepare("SELECT ar.id,ar.recording_mode,ar.duration_seconds,ar.status,ar.started_at,ar.created_at,ar.document_id,pd.title,pd.file_size,pd.visible_to_patient FROM appointment_recordings ar LEFT JOIN patient_documents pd ON pd.tenant_id=ar.tenant_id AND pd.id=ar.document_id WHERE ar.tenant_id=? AND ar.appointment_id=? AND ar.provider='daily' ORDER BY ar.created_at DESC");
    $stmt->bind_param('ii',$tenantId,$appointmentId); $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
