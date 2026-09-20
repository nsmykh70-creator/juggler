;; Juggler WASM filter: injects a header into every request forwarded to
;; the route the plugin is attached to.
;;
;; ABI (see src/main.rs, "WASM plugin pipeline"):
;;   host -> plugin: request path at ptr 1048576 (1 MiB), body at ptr 0
;;   juggler.alloc(size)          -> scratch pointer (zeroed)
;;   juggler.add_header(k,kl,v,vl)
;;   juggler.path_len()           -> length of the published request path
(module
  (import "juggler" "add_header" (func $add_header (param i32 i32 i32 i32) (result i32)))

  ;; 17 pages: the fixed ABI layout (path window at 1 MiB, scratch behind
  ;; it) must fit the module's own declared memory, because data segments
  ;; are applied at instantiation, before the host can grow anything.
  (memory (export "memory") 17)

  (data (i32.const 1052672) "X-Wasm-Juggler")
  (data (i32.const 1052700) "lets-juggle")

  ;; Header phase (called for every request): attach one header to the
  ;; forwarded request via the juggler.add_header host function.
  (func (export "on_request")
    (drop (call $add_header
      (i32.const 1052672) (i32.const 14)   ;; key "X-Wasm-Juggler"
      (i32.const 1052700) (i32.const 11)))) ;; value "lets-juggle"

  ;; Body phase (optional): -1 = leave the request body untouched.
  (func (export "transform_body") (param i32 i32) (result i32)
    (i32.const -1))
)
