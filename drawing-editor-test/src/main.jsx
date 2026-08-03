import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import {
  Excalidraw,
  exportToBlob,
  loadLibraryFromBlob,
  serializeAsJSON
} from '@excalidraw/excalidraw';
import '@excalidraw/excalidraw/index.css';
import './styles.css';

const STORAGE_KEY = 'sgpraxis-drawing-editor-test';
const externalConfig = window.SG_DRAWING_EDITOR_CONFIG || {};
const isEmbedded = !!externalConfig.embedded;

function postToHost(type, payload = {}) {
  if (!isEmbedded || !window.parent) return;
  window.parent.postMessage({ source: 'sgpraxis-drawing-editor', type, ...payload }, window.location.origin);
}

function downloadBlob(blob, fileName) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = fileName;
  link.click();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function blobToDataUrl(blob) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || ''));
    reader.onerror = reject;
    reader.readAsDataURL(blob);
  });
}

function safeScenePayload(elements, appState, files) {
  return {
    elements,
    appState: {
      ...appState,
      collaborators: undefined,
      selectedElementIds: undefined,
      selectedGroupIds: undefined
    },
    files
  };
}

function normalizeScenePayload(payload) {
  return {
    elements: payload?.elements || [],
    appState: {
      ...(payload?.appState || {}),
      viewBackgroundColor: payload?.appState?.viewBackgroundColor || '#ffffff'
    },
    files: payload?.files || {},
    scrollToContent: true
  };
}

function buildSaveUrl(config, overrides = {}) {
  const url = new URL(config.saveUrl || 'api/admin.php?action=save_drawing_patient_document', window.location.href);
  const params = url.searchParams;
  params.set('patient_id', parseInt(overrides.patientId ?? config.patientId ?? 0, 10));
  params.set('document_id', parseInt(overrides.documentId ?? config.documentId ?? 0, 10));
  params.set('appointment_id', parseInt(overrides.appointmentId ?? config.appointmentId ?? 0, 10));
  params.set('title', overrides.title || config.title || 'Dibujo');
  params.set('visible_to_patient', overrides.visibleToPatient ? 1 : 0);
  params.set('autosave', overrides.autosave ? 1 : 0);
  return url.toString();
}

function App() {
  const fileInputRef = useRef(null);
  const [excalidrawAPI, setExcalidrawAPI] = useState(null);
  const [scene, setScene] = useState(null);
  const [initialData, setInitialData] = useState(null);
  const [status, setStatus] = useState('Preparando pizarra...');
  const [statusType, setStatusType] = useState('muted');
  const [dirty, setDirty] = useState(false);
  const [documentId, setDocumentId] = useState(parseInt(externalConfig.documentId || 0, 10));
  const [title, setTitle] = useState(externalConfig.title || 'Dibujo');
  const [loading, setLoading] = useState(isEmbedded && externalConfig.loadUrl);

  const primaryColor = useMemo(() => {
    const value = String(externalConfig.primaryColor || '#8f7bc3');
    return /^#[0-9a-fA-F]{6}$/.test(value) ? value : '#8f7bc3';
  }, []);

  const notifyStatus = useCallback((message, type = 'muted') => {
    setStatus(message);
    setStatusType(type);
    postToHost(type === 'danger' ? 'error' : 'status', { message, error: type === 'danger' });
  }, []);

  const markDirty = useCallback((value) => {
    setDirty(value);
    if (value) {
      postToHost('dirty', { dirty: true });
    }
  }, []);

  useEffect(() => {
    document.documentElement.style.setProperty('--sg-drawing-primary', primaryColor);
  }, [primaryColor]);

  useEffect(() => {
    let active = true;
    async function loadInitialScene() {
      if (isEmbedded && externalConfig.loadUrl) {
        try {
          const response = await fetch(externalConfig.loadUrl, { credentials: 'same-origin' });
          const data = await response.json();
          if (!active) return;
          if (!data.success) {
            notifyStatus(data.error || 'No se pudo cargar el dibujo.', 'danger');
            setLoading(false);
            return;
          }
          const payload = normalizeScenePayload(data.scene || {});
          setInitialData(payload);
          setScene(payload);
          setLoading(false);
          notifyStatus('Dibujo cargado.', 'success');
        } catch (error) {
          console.error(error);
          if (active) {
            notifyStatus('Error de conexión al cargar el dibujo.', 'danger');
            setLoading(false);
          }
        }
        return;
      }
      if (!isEmbedded) {
        try {
          const saved = window.localStorage.getItem(STORAGE_KEY);
          if (saved) {
            const payload = normalizeScenePayload(JSON.parse(saved));
            setInitialData(payload);
            setScene(payload);
            notifyStatus('Dibujo anterior cargado desde este navegador.', 'success');
            setLoading(false);
            return;
          }
        } catch (error) {
          console.warn('No se pudo cargar el dibujo guardado', error);
        }
      }
      setInitialData(null);
      setLoading(false);
      notifyStatus(isEmbedded ? 'Pizarra lista.' : 'Pizarra lista.', 'muted');
    }
    loadInitialScene();
    return () => {
      active = false;
    };
  }, [notifyStatus]);

  useEffect(() => {
    if (!excalidrawAPI) return;
    const timer = window.setTimeout(() => {
      try {
        excalidrawAPI.setActiveTool({
          type: externalConfig.defaultTool || 'freedraw',
          locked: true
        });
      } catch (error) {
        console.warn('No se pudo preseleccionar el lápiz', error);
      }
    }, 250);
    return () => window.clearTimeout(timer);
  }, [excalidrawAPI]);

  useEffect(() => {
    if (!excalidrawAPI || !Array.isArray(externalConfig.libraryUrls) || !externalConfig.libraryUrls.length) return undefined;
    let active = true;
    async function loadPresetLibraries() {
      try {
        const collections = await Promise.all(externalConfig.libraryUrls.map(async (url) => {
          const response = await fetch(url, { credentials: 'same-origin' });
          if (!response.ok) throw new Error(`No se pudo cargar ${url}`);
          return loadLibraryFromBlob(await response.blob(), 'published');
        }));
        if (!active) return;
        await excalidrawAPI.updateLibrary({
          libraryItems: collections.flat(),
          merge: true,
          openLibraryMenu: false
        });
        notifyStatus('Pizarra y biblioteca de recursos listas.', 'success');
      } catch (error) {
        console.warn('No se pudieron precargar las librerías de Excalidraw.', error);
        notifyStatus('Pizarra lista. Algunas imágenes prediseñadas no se pudieron cargar.', 'warning');
      }
    }
    loadPresetLibraries();
    return () => { active = false; };
  }, [excalidrawAPI, notifyStatus]);

  useEffect(() => {
    if (!excalidrawAPI) return undefined;
    let frame = 0;
    const refreshViewport = () => {
      window.cancelAnimationFrame(frame);
      frame = window.requestAnimationFrame(() => {
        try { excalidrawAPI.refresh(); } catch (error) { /* ResizeObserver cubre versiones sin refresh. */ }
      });
    };
    window.addEventListener('resize', refreshViewport);
    window.addEventListener('orientationchange', refreshViewport);
    return () => {
      window.cancelAnimationFrame(frame);
      window.removeEventListener('resize', refreshViewport);
      window.removeEventListener('orientationchange', refreshViewport);
    };
  }, [excalidrawAPI]);

  useEffect(() => {
    const translations = new Map([
      ['Find on canvas', 'Buscar en el lienzo'],
      ['Find text on canvas...', 'Buscar texto en el lienzo...'],
      ['No matches found...', 'No se encontraron coincidencias...'],
      ['result', 'resultado'],
      ['results', 'resultados']
    ]);

    const translateSearchUi = () => {
      document.querySelectorAll('.drawing-test-editor .excalidraw *').forEach((element) => {
        if (element.childNodes.length === 1 && element.firstChild?.nodeType === Node.TEXT_NODE) {
          const current = element.textContent?.trim() || '';
          if (translations.has(current)) {
            element.textContent = translations.get(current);
          }
        }
        const ariaLabel = element.getAttribute?.('aria-label');
        if (ariaLabel && translations.has(ariaLabel)) {
          element.setAttribute('aria-label', translations.get(ariaLabel));
        }
        const placeholder = element.getAttribute?.('placeholder');
        if (placeholder && translations.has(placeholder)) {
          element.setAttribute('placeholder', translations.get(placeholder));
        }
      });
    };

    translateSearchUi();
    const observer = new MutationObserver(translateSearchUi);
    observer.observe(document.body, { childList: true, subtree: true });
    return () => observer.disconnect();
  }, []);

  const handleChange = useCallback((elements, appState, files) => {
    const payload = safeScenePayload(elements, appState, files);
    setScene(payload);
    markDirty(true);
    if (!isEmbedded) {
      try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
        setStatus('Autoguardado en este navegador.');
        setStatusType('muted');
      } catch (error) {
        console.warn('No se pudo autoguardar el dibujo', error);
        setStatus('No se pudo autoguardar localmente.');
        setStatusType('danger');
      }
    }
  }, [markDirty]);

  const clearDrawing = useCallback(() => {
    if (!excalidrawAPI) return;
    excalidrawAPI.resetScene();
    window.localStorage.removeItem(STORAGE_KEY);
    setScene(null);
    markDirty(false);
    notifyStatus('Dibujo en blanco preparado.', 'success');
  }, [excalidrawAPI, markDirty, notifyStatus]);

  const currentScene = useCallback(() => {
    if (!excalidrawAPI) return scene;
    return safeScenePayload(
      excalidrawAPI.getSceneElements(),
      excalidrawAPI.getAppState(),
      excalidrawAPI.getFiles()
    );
  }, [excalidrawAPI, scene]);

  const exportPngBlob = useCallback(async () => {
    const payload = currentScene();
    if (!payload?.elements?.length) {
      throw new Error('No hay nada que guardar todavía.');
    }
    return exportToBlob({
      elements: payload.elements,
      appState: {
        ...payload.appState,
        exportBackground: true,
        exportWithDarkMode: false,
        viewBackgroundColor: payload.appState?.viewBackgroundColor || '#ffffff'
      },
      files: payload.files || null,
      mimeType: 'image/png',
      quality: 0.95,
      exportPadding: 24
    });
  }, [currentScene]);

  const saveEmbeddedDrawing = useCallback(async ({ autosave = false } = {}) => {
    if (!isEmbedded) return;
    try {
      const payload = currentScene();
      if (!payload?.elements?.length) {
        notifyStatus('No hay nada que guardar todavía.', 'warning');
        postToHost('error', { message: 'No hay nada que guardar todavía.' });
        return;
      }
      notifyStatus(autosave ? 'Autoguardando dibujo...' : 'Guardando dibujo...', 'muted');
      const sceneJson = serializeAsJSON(payload.elements, payload.appState || {}, payload.files || {}, 'local');
      const pngDataUrl = await blobToDataUrl(await exportPngBlob());
      const response = await fetch(buildSaveUrl(
        { ...externalConfig, documentId, title },
        { documentId, title, autosave }
      ), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ sceneJson, pngDataUrl })
      });
      const data = await response.json();
      if (!data.success) {
        throw new Error(data.error || 'No se pudo guardar el dibujo.');
      }
      const nextDocumentId = parseInt(data.document_id || documentId || 0, 10);
      if (nextDocumentId > 0) {
        setDocumentId(nextDocumentId);
        externalConfig.documentId = nextDocumentId;
      }
      if (data.title) {
        setTitle(data.title);
        externalConfig.title = data.title;
      }
      markDirty(false);
      notifyStatus(autosave ? 'Autoguardado correctamente.' : 'Dibujo guardado correctamente.', 'success');
      postToHost('saved', {
        documentId: nextDocumentId,
        title: data.title || title,
        autosave,
        savedAt: data.saved_at || ''
      });
    } catch (error) {
      console.error(error);
      notifyStatus(error.message || 'No se pudo guardar el dibujo.', 'danger');
      postToHost('error', { message: error.message || 'No se pudo guardar el dibujo.' });
    }
  }, [currentScene, documentId, exportPngBlob, markDirty, notifyStatus, title]);

  const exportPng = useCallback(async () => {
    try {
      const blob = await exportPngBlob();
      downloadBlob(blob, `dibujo-sgpraxis-${new Date().toISOString().slice(0, 10)}.png`);
      notifyStatus('PNG exportado.', 'success');
    } catch (error) {
      notifyStatus(error.message || 'No se pudo exportar el PNG.', 'danger');
    }
  }, [exportPngBlob, notifyStatus]);

  const exportEditable = useCallback(() => {
    const payload = currentScene();
    if (!payload?.elements?.length) {
      notifyStatus('No hay nada que guardar todavía.', 'warning');
      return;
    }
    const json = serializeAsJSON(
      payload.elements,
      payload.appState || {},
      payload.files || {},
      'local'
    );
    downloadBlob(
      new Blob([json], { type: 'application/vnd.excalidraw+json' }),
      `dibujo-sgpraxis-${new Date().toISOString().slice(0, 10)}.excalidraw`
    );
    notifyStatus('Archivo editable exportado.', 'success');
  }, [currentScene, notifyStatus]);

  const importEditable = useCallback(async (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file || !excalidrawAPI) return;
    try {
      const payload = normalizeScenePayload(JSON.parse(await file.text()));
      excalidrawAPI.updateScene(payload);
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
      setScene(payload);
      markDirty(false);
      notifyStatus('Dibujo editable importado.', 'success');
    } catch (error) {
      console.error(error);
      notifyStatus('No se pudo importar el archivo. Debe ser un .excalidraw válido.', 'danger');
    }
  }, [excalidrawAPI, markDirty, notifyStatus]);

  useEffect(() => {
    if (!isEmbedded) return undefined;
    const handler = (event) => {
      if (event.origin !== window.location.origin) return;
      const data = event.data || {};
      if (data.source !== 'sgpraxis-drawing-editor-host') return;
      if (data.type === 'save') {
        saveEmbeddedDrawing({ autosave: false });
      } else if (data.type === 'export-png') {
        exportPng();
      } else if (data.type === 'new') {
        if (excalidrawAPI) {
          excalidrawAPI.resetScene();
          setDocumentId(0);
          externalConfig.documentId = 0;
          if (data.title) {
            setTitle(data.title);
            externalConfig.title = data.title;
          }
          markDirty(true);
          notifyStatus('Dibujo en blanco preparado.', 'success');
        }
      }
    };
    window.addEventListener('message', handler);
    return () => window.removeEventListener('message', handler);
  }, [excalidrawAPI, exportPng, markDirty, notifyStatus, saveEmbeddedDrawing]);

  useEffect(() => {
    if (!isEmbedded || !externalConfig.autosaveMs) return undefined;
    const interval = window.setInterval(() => {
      if (dirty) {
        saveEmbeddedDrawing({ autosave: true });
      }
    }, Math.max(10000, parseInt(externalConfig.autosaveMs, 10) || 30000));
    return () => window.clearInterval(interval);
  }, [dirty, saveEmbeddedDrawing]);

  const editor = (
    <section className={isEmbedded ? 'drawing-test-editor is-embedded' : 'drawing-test-editor'}>
      {loading ? (
        <div className="drawing-test-loading">Cargando dibujo...</div>
      ) : (
        <Excalidraw
          excalidrawAPI={setExcalidrawAPI}
          initialData={initialData}
          onChange={handleChange}
          langCode="es-ES"
          name={title || 'Dibujo SGPraxis'}
          UIOptions={{
            canvasActions: {
              loadScene: false,
              saveToActiveFile: false,
              export: false,
              saveAsImage: false,
              toggleTheme: false
            }
          }}
        />
      )}
    </section>
  );

  if (isEmbedded) {
    return (
      <main className="drawing-test-shell is-embedded">
        {editor}
      </main>
    );
  }

  return (
    <main className="drawing-test-shell">
      <header className="drawing-test-header">
        <div>
          <p className="drawing-test-kicker">Prueba temporal</p>
          <h1>Editor de dibujos</h1>
          <span>Dibujo libre con pen, dedo o ratón. Colores, formas, texto e imágenes.</span>
        </div>
        <div className="drawing-test-actions">
          <button type="button" className="drawing-test-btn is-outline" onClick={clearDrawing}>
            Nuevo dibujo
          </button>
          <button type="button" className="drawing-test-btn is-outline" onClick={() => fileInputRef.current?.click()}>
            Cargar editable
          </button>
          <button type="button" className="drawing-test-btn is-outline" onClick={exportEditable}>
            Descargar editable
          </button>
          <button type="button" className="drawing-test-btn is-primary" onClick={exportPng}>
            Descargar PNG
          </button>
          <input
            ref={fileInputRef}
            type="file"
            accept=".excalidraw,application/json,application/vnd.excalidraw+json"
            onChange={importEditable}
            hidden
          />
        </div>
      </header>

      <section className={`drawing-test-status is-${statusType}`} aria-live="polite">
        <span>{status}</span>
        {dirty && <strong>Autoguardado activo</strong>}
      </section>

      {editor}
    </main>
  );
}

createRoot(document.getElementById('drawing-editor-test-root')).render(<App />);
