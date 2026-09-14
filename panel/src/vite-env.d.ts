/// <reference types="vite/client" />

/**
 * `import.meta.env` is Vite's build-time constant object. Declaring the
 * variables we actually read keeps a typo in an env name a compile error
 * rather than a silent `undefined`. Unset is the CURRENT production
 * state — one origin, relative path — so the optional marker here is
 * real rather than laziness (FR-15.24, cancelled by the client
 * 2026-09-14).
 */
interface ImportMetaEnv {
  /** Absolute API origin + prefix, e.g. https://api.mydana.app/api/v1 */
  readonly VITE_API_BASE?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
