import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { DocxEditor, createEmptyDocument } from '@eigenpal/docx-editor-react';
import '@eigenpal/docx-editor-react/styles.css';
import './styles.css';
import es from './i18n/es';

const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
const externalConfig = window.SG_DOCX_EDITOR_CONFIG || {};
const embedded = !!externalConfig.embedded;

function normalizeDocxName(value) {
  const raw = String(value || '').trim() || 'documento';
  return raw.toLowerCase().endsWith('.docx') ? raw : `${raw}.docx`;
}

function downloadBuffer(buffer, fileName) {
  const blob = new Blob([buffer], { type: DOCX_MIME });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = fileName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

function postToParent(type, payload = {}) {
  if (!embedded || !window.parent) return;
  window.parent.postMessage({ source: 'sgpraxis-docx-editor', type, ...payload }, window.location.origin);
}

function App() {
  const editorRef = useRef(null);
  const autosaveTimerRef = useRef(null);
  const saveInProgressRef = useRef(false);
  const [documentBuffer, setDocumentBuffer] = useState(undefined);
  const [documentModel, setDocumentModel] = useState(null);
  const [documentId, setDocumentId] = useState(parseInt(externalConfig.documentId || 0, 10));
  const [patientId] = useState(parseInt(externalConfig.patientId || 0, 10));
  const [appointmentId] = useState(parseInt(externalConfig.appointmentId || 0, 10));
  const [title, setTitle] = useState(String(externalConfig.title || 'Documento'));
  const [fileName, setFileName] = useState(normalizeDocxName(externalConfig.fileName || externalConfig.title || 'documento.docx'));
  const [status, setStatus] = useState(embedded ? 'Preparando editor...' : 'Sube un archivo .docx o crea un documento vacío para empezar.');
  const [dirty, setDirty] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [loading, setLoading] = useState(false);

  const hasDocument = documentBuffer !== undefined || documentModel !== null;
  const autosaveMs = Math.max(10000, parseInt(externalConfig.autosaveMs || 30000, 10));
  const normalizedDownloadName = useMemo(() => normalizeDocxName(fileName || title), [fileName, title]);

  const setStatusAndNotify = useCallback((message, extra = {}) => {
    setStatus(message);
    postToParent('status', { message, dirty, documentId, ...extra });
  }, [dirty, documentId]);

  const loadArrayBuffer = useCallback((buffer, nextName = '') => {
    setDocumentModel(null);
    setDocumentBuffer(buffer);
    if (nextName) {
      setFileName(normalizeDocxName(nextName));
      setTitle(nextName.replace(/\.docx$/i, ''));
    }
    setDirty(false);
  }, []);

  const createBlankDocument = useCallback((nextTitle = '', markDirty = false) => {
    const cleanTitle = String(nextTitle || title || 'Documento').trim() || 'Documento';
    setTitle(cleanTitle);
    setFileName(normalizeDocxName(cleanTitle));
    setDocumentBuffer(undefined);
    setDocumentModel(createEmptyDocument());
    setDirty(markDirty);
    setStatusAndNotify('Documento en blanco creado.', { dirty: markDirty });
  }, [setStatusAndNotify, title]);

  const openUploadedFile = useCallback(async (file) => {
    if (!file) return;
    if (!String(file.name || '').toLowerCase().endsWith('.docx')) {
      setStatusAndNotify('El archivo debe ser .docx.', { error: true });
      return;
    }
    const buffer = await file.arrayBuffer();
    loadArrayBuffer(buffer, file.name);
    setDirty(true);
    setStatusAndNotify(`Documento cargado: ${file.name}`, { dirty: true });
  }, [loadArrayBuffer, setStatusAndNotify]);

  const saveDocument = useCallback(async (options = {}) => {
    if (!editorRef.current || !hasDocument || saveInProgressRef.current) {
      return null;
    }
    const autosave = !!options.autosave;
    saveInProgressRef.current = true;
    setIsSaving(true);
    try {
      const saved = await editorRef.current.save();
      if (!saved) {
        throw new Error('No se pudo generar el DOCX editado.');
      }
      if (!externalConfig.saveUrl || !patientId) {
        downloadBuffer(saved, normalizedDownloadName);
        setDirty(false);
        setStatusAndNotify(`Descargado: ${normalizedDownloadName}`, { dirty: false });
        return { downloaded: true };
      }
      const params = new URLSearchParams({
        patient_id: String(patientId),
        document_id: String(documentId || 0),
        appointment_id: String(appointmentId || 0),
        title: title || normalizedDownloadName,
        autosave: autosave ? '1' : '0'
      });
      const response = await fetch(`${externalConfig.saveUrl}&${params.toString()}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': DOCX_MIME },
        body: saved
      });
      const result = await response.json();
      if (!result.success) {
        throw new Error(result.error || 'No se pudo guardar el documento.');
      }
      const nextDocumentId = parseInt(result.document_id || documentId || 0, 10);
      if (nextDocumentId > 0) {
        setDocumentId(nextDocumentId);
      }
      setDirty(false);
      const message = autosave ? 'Autoguardado correctamente.' : 'Documento guardado correctamente.';
      setStatusAndNotify(message, {
        dirty: false,
        saved: true,
        documentId: nextDocumentId,
        savedAt: result.saved_at || ''
      });
      postToParent('saved', {
        documentId: nextDocumentId,
        patientId,
        appointmentId,
        title: result.title || title,
        autosave
      });
      return result;
    } catch (error) {
      const message = `Error al guardar: ${error?.message || 'error desconocido'}`;
      setStatusAndNotify(message, { error: true });
      postToParent('error', { message });
      return null;
    } finally {
      saveInProgressRef.current = false;
      setIsSaving(false);
    }
  }, [appointmentId, dirty, documentId, hasDocument, normalizedDownloadName, patientId, setStatusAndNotify, title]);

  useEffect(() => {
    if (externalConfig.loadUrl) {
      setLoading(true);
      fetch(externalConfig.loadUrl, { credentials: 'same-origin' })
        .then((response) => {
          if (!response.ok) throw new Error('No se pudo cargar el documento.');
          return response.arrayBuffer();
        })
        .then((buffer) => {
          loadArrayBuffer(buffer, externalConfig.fileName || externalConfig.title || 'documento.docx');
          setStatusAndNotify('Documento cargado.');
        })
        .catch((error) => {
          setStatusAndNotify(error?.message || 'No se pudo cargar el documento.', { error: true });
        })
        .finally(() => setLoading(false));
    } else if (embedded) {
      createBlankDocument(externalConfig.title || 'Documento', false);
    }
  }, []);

  useEffect(() => {
    const onMessage = async (event) => {
      if (event.origin !== window.location.origin) return;
      const data = event.data || {};
      if (data.source !== 'sgpraxis-docx-editor-host') return;
      if (data.type === 'save') {
        await saveDocument({ autosave: false });
      } else if (data.type === 'new') {
        createBlankDocument(data.title || 'Documento', true);
      } else if (data.type === 'set-title') {
        const nextTitle = String(data.title || '').trim();
        if (nextTitle) {
          setTitle(nextTitle);
          setFileName(normalizeDocxName(nextTitle));
          setDirty(true);
        }
      } else if (data.type === 'upload-buffer' && data.buffer) {
        loadArrayBuffer(data.buffer, data.fileName || 'documento.docx');
        setDirty(true);
        setStatusAndNotify(`Documento cargado: ${data.fileName || 'documento.docx'}`, { dirty: true });
      }
    };
    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
  }, [createBlankDocument, loadArrayBuffer, saveDocument, setStatusAndNotify]);

  useEffect(() => {
    if (!embedded || !dirty || !hasDocument || isSaving) return;
    window.clearTimeout(autosaveTimerRef.current);
    autosaveTimerRef.current = window.setTimeout(() => {
      saveDocument({ autosave: true });
    }, autosaveMs);
    return () => window.clearTimeout(autosaveTimerRef.current);
  }, [autosaveMs, dirty, embedded, hasDocument, isSaving, saveDocument]);

  const handleLocalUpload = useCallback((event) => {
    openUploadedFile(event.target.files?.[0]);
    event.target.value = '';
  }, [openUploadedFile]);

  const handleChange = useCallback(() => {
    setDirty(true);
    postToParent('dirty', { dirty: true, documentId });
  }, [documentId]);

  return (
    <main className={`docx-test-shell ${embedded ? 'is-embedded' : ''}`}>
      {!embedded && (
        <section className="docx-test-header">
          <div>
            <p className="docx-test-kicker">Prueba temporal</p>
            <h1>Editor DOCX en navegador</h1>
            <p>Esta prueba carga el documento en tu navegador, permite editarlo y descarga una copia .docx.</p>
          </div>
          <div className="docx-test-actions">
            <label className="docx-test-button docx-test-button-primary">
              Abrir .docx
              <input type="file" accept=".docx" onChange={handleLocalUpload} />
            </label>
            <button className="docx-test-button" type="button" onClick={() => createBlankDocument('Documento', true)}>
              Documento vacío
            </button>
            <button className="docx-test-button" type="button" onClick={() => saveDocument()} disabled={!hasDocument || isSaving}>
              {isSaving ? 'Preparando...' : 'Descargar .docx'}
            </button>
          </div>
        </section>
      )}

      {!embedded && (
        <div className="docx-test-status">
          {isSaving ? 'Guardando...' : (loading ? 'Cargando documento...' : status)}
          {dirty && <span className="docx-test-dirty">Cambios sin guardar</span>}
        </div>
      )}

      <section className="docx-test-editor">
        {hasDocument ? (
          <DocxEditor
            ref={editorRef}
            document={documentBuffer === undefined ? documentModel : undefined}
            documentBuffer={documentBuffer}
            mode="editing"
            showToolbar
            showFileOpen={false}
            showHelpMenu={false}
            showRuler={!embedded}
            showZoomControl={!embedded}
            documentName={title}
            documentNameEditable={false}
            i18n={es}
            onChange={handleChange}
          />
        ) : (
          <div className="docx-test-empty">
            <strong>Sin documento abierto</strong>
            <span>Sube un .docx de prueba o crea uno vacío.</span>
          </div>
        )}
      </section>
    </main>
  );
}

createRoot(document.getElementById('docx-editor-test-root')).render(<App />);
