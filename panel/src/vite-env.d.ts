/// <reference types="vite/client" />

/**
 * `import.meta.env` is Vite's build-time constant object. Declaring the
 * variables we actually read keeps a typo in an env name a compile error
 * rather than a silent `undefined` that falls back to a relative API
 * path — which, on admin.mydana.app, would fail against a host that
 * serves no API (FR-15.24).
 */
interface ImportMetaEnv {
  /** Absolute API origin + prefix, e.g. https://api.mydana.app/api/v1 */
  readonly VITE_API_BASE?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
