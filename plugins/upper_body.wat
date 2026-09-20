;; Juggler WASM filter: rewrites the request body, uppercasing every ASCII
;; letter. Demonstrates the transform_body contract: copy the input, then
;; return a pointer to a buffer holding (new_len: u32 LE) + transformed
;; bytes. The buffer must come from juggler.alloc (never the input pointer,
;; which overlaps the host-written body window).
(module
  (import "juggler" "alloc" (func $alloc (param i32) (result i32)))

  ;; 17 pages: see the ABI layout note in add_header.wat.
  (memory (export "memory") 17)

  (func (export "transform_body") (param $ptr i32) (param $len i32) (result i32)
    (local $out i32)
    (local $i i32)
    (local $b i32)

    ;; out = alloc(4 + len): 4-byte length header + rewritten body.
    (local.set $out (call $alloc (i32.add (i32.const 4) (local.get $len))))
    (if (i32.eqz (local.get $out))
      (then (return (i32.const -1))))
    (i32.store (local.get $out) (local.get $len))

    (block $done
      (loop $again
        (br_if $done (i32.ge_u (local.get $i) (local.get $len)))
        (local.set $b
          (i32.load8_u (i32.add (local.get $ptr) (local.get $i))))
        ;; ASCII a-z -> A-Z
        (if (i32.and (i32.ge_u (local.get $b) (i32.const 97))
                     (i32.le_u (local.get $b) (i32.const 122)))
          (then (local.set $b (i32.sub (local.get $b) (i32.const 32)))))
        (i32.store8
          (i32.add (i32.add (local.get $out) (i32.const 4)) (local.get $i))
          (local.get $b))
        (local.set $i (i32.add (local.get $i) (i32.const 1)))
        (br $again)))

    (local.get $out))
)
