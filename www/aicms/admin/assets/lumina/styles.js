export const luminaStyles = `
/* Lumina Pro Editor CSS vFinal Ultimate - Fixed Scroll Architecture */

/* --- CORE CONTAINER & UI --- */
.lumina-container { 
    border: none;
    border-radius: 0; 
    background: #fff; 
    font-family: 'Inter', system-ui, sans-serif; 
    display: flex; 
    flex-direction: column; 
    margin-bottom: 0; 
    position: relative; 
    box-sizing: border-box; 
    height: auto !important; 
    min-height: 0;
    overflow: visible !important;
    width: 100% !important;
    max-width: 100% !important;
}

.lumina-fullscreen { 
    position: fixed; 
    top: 0; 
    left: 0; 
    right: 0; 
    bottom: 0; 
    z-index: 99999; 
    border-radius: 0; 
    border: 0; 
    height: 100vh !important; 
    overflow-y: auto !important;
}

/* Sticky Toolbar Container - Default Static */
.lumina-toolbar-container {
    width: 100%;
    background: #f8fafc;
    z-index: 1000;
    position: relative;
    flex-shrink: 0;
    border-bottom: 1px solid #e2e8f0;
}

/* Apply Sticky ONLY when sticky-mode class is present (user setting) */
.lumina-sticky-mode .lumina-toolbar-container {
    position: sticky !important;
    top: var(--lumina-sticky-top, 0px) !important;
    z-index: 999;
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
    background: #f8fafc;
    border-bottom: 2px solid #2563eb !important;
    transition: box-shadow 0.2s, border-color 0.2s;
}

/* In fullscreen + sticky mode: top = 0 (no admin topbar visible) */
.lumina-fullscreen.lumina-sticky-mode .lumina-toolbar-container {
    top: 0 !important;
}

.lumina-toolbar { 
    padding: 8px; 
    background: #f8fafc; 
    display: flex; 
    flex-wrap: wrap; 
    gap: 4px; 
    align-items: center; 
    box-sizing: border-box; 
    width: 100%;
    position: relative;
}

.lumina-toolbar-placeholder { display: none; }

/* Buttons & Inputs */
.lumina-btn { 
    width: 32px; 
    height: 32px; 
    min-width: 32px; 
    min-height: 32px;
    border: 1px solid transparent; 
    background: transparent; 
    border-radius: 6px; 
    color: #475569; 
    cursor: pointer; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    transition: all 0.2s; 
    padding: 0; 
}
.lumina-btn:hover { background: #e2e8f0; color: #1e293b; }
.lumina-btn.active { background: #dbeafe; color: #2563eb; border-color: #bfdbfe; }

/* Ensure SVG icons are visible */
.lumina-btn svg {
    width: 18px;
    height: 18px;
    stroke: currentColor;
    stroke-width: 2;
    fill: none;
    display: block; 
}

.lumina-btn-ai { width: auto; padding: 0 10px; color: #7c3aed; background: #f3e8ff; font-size: 13px; font-weight: 600; }
.lumina-btn-ai:hover { background: #ddd6fe; color: #6d28d9; }
.lumina-sep { width: 1px; height: 20px; background: #cbd5e1; margin: 0 4px; }
.lumina-sep-hor { width: 100%; height: 1px; background: #e2e8f0; margin: 4px 0; }
.lumina-select { height: 30px; border: 1px solid #e2e8f0; border-radius: 4px; background: #fff; color: #334155; font-size: 13px; padding: 0 4px; outline: none; cursor: pointer; }

/* Dropdowns */
.lumina-dropdown-container {
    position: relative;
    display: inline-block;
}
.lumina-dropdown-content {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    background-color: #ffffff;
    min-width: 160px;
    box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
    z-index: 100002 !important;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 4px 0;
}
.lumina-dropdown-container:hover .lumina-dropdown-content {
    display: block;
}
.lumina-dropdown-item {
    padding: 8px 12px;
    font-size: 13px;
    color: #334155;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: background 0.1s;
}
.lumina-dropdown-item:hover {
    background-color: #f1f5f9;
    color: #0f172a;
}
.lumina-dropdown-item svg {
    width: 14px;
    height: 14px;
    opacity: 0.7;
}

/* Editor Area */
.lumina-wrapper { 
    position: relative; 
    display: flex; 
    flex-direction: column; 
    flex: 1; 
    overflow-y: visible; 
    overflow-x: visible;
    height: auto !important;
    width: 100% !important;
    max-width: 100% !important;
}

.lumina-editor { 
    padding: 40px; 
    outline: none; 
    color: #334155; 
    line-height: 1.6; 
    background: #fff; 
    flex: 1; 
    position: relative; 
    height: auto !important;
    min-height: 500px;
    overflow: visible; 
    cursor: text; 
    padding-bottom: 200px;
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box;
}

.lumina-tooltip, .lumina-editor [title] {
    border-bottom: 2px dotted #94a3b8;
    cursor: help;
}

.lumina-editor a, 
.lumina-editor button,
.lumina-editor .lumina-action-btn {
    cursor: text;
}

.lumina-editor p { margin-bottom: 1em; } 
.lumina-editor h1 { font-size: 2em; font-weight: 700; margin-bottom: 0.5em; color: #1e293b; } 
.lumina-editor h2 { font-size: 1.5em; font-weight: 600; margin-bottom: 0.5em; color: #1e293b; } 
.lumina-editor img { max-width: 100%; border-radius: 4px; cursor: pointer; display: block; height: auto; } 
.lumina-editor table { width: 100%; border-collapse: collapse; margin: 10px 0; } 
.lumina-editor td, .lumina-editor th { border: 1px solid #cbd5e1; padding: 8px; }

/* Status Bar */
.lumina-statusbar {
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    padding: 6px 12px;
    font-size: 11px;
    color: #64748b;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
    z-index: 50;
    user-select: none;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
}

.lumina-breadcrumbs {
    display: flex;
    align-items: center;
    overflow: hidden;
    white-space: nowrap;
    mask-image: linear-gradient(to right, black 90%, transparent 100%);
    max-width: 70%;
}

.lumina-breadcrumb-item {
    cursor: pointer;
    padding: 2px 6px;
    border-radius: 4px;
    transition: background 0.1s;
    font-weight: 500;
    color: #475569;
}

.lumina-breadcrumb-item:hover {
    background: #e2e8f0;
    color: #0f172a;
}

.lumina-breadcrumb-sep {
    margin: 0 2px;
    color: #94a3b8;
}

.lumina-stat-item {
    font-variant-numeric: tabular-nums;
    font-weight: 600;
}

.lumina-wordcount-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}
.lumina-wordcount-table th, .lumina-wordcount-table td {
    border: 1px solid #e2e8f0;
    padding: 8px;
    text-align: left;
    font-size: 13px;
    color: #334155;
}
.lumina-wordcount-table th {
    background: #f8fafc;
    font-weight: 600;
}
.lumina-modal-hint-box {
    font-size: 12px;
    color: #64748b;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 8px 10px;
}
.lumina-modal-warn-box {
    padding: 8px;
    background: #fef3c7;
    border: 1px solid #fde68a;
    border-radius: 6px;
    font-size: 12px;
    color: #92400e;
}

.lumina-editor blockquote {
    border-left: 4px solid #3b82f6;
    padding: 10px 20px;
    margin: 20px 0;
    color: #475569;
    background-color: #f8fafc;
    border-radius: 0 4px 4px 0;
    font-style: italic;
}

/* --- ADVANCED CODE BLOCK --- */
.lumina-code-wrapper {
    margin: 1.5em 0;
    background-color: #282c34; 
    border-radius: 8px;
    overflow: hidden;
    font-family: 'Fira Code', 'Consolas', monospace;
    position: relative;
    border: 1px solid #3e4451;
}

.lumina-code-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 12px;
    background-color: #21252b;
    border-bottom: 1px solid #3e4451;
    user-select: none;
}

.lumina-code-lang-select {
    background: #282c34;
    color: #abb2bf;
    border: 1px solid #3e4451;
    border-radius: 4px;
    font-size: 11px;
    padding: 2px 4px;
    margin-right: 8px;
    outline: none;
    cursor: pointer;
}

.lumina-code-actions {
    display: flex;
    gap: 6px;
    margin-left: auto;
}

.lumina-code-copy, .lumina-code-edit {
    background: transparent;
    border: 1px solid #abb2bf;
    color: #abb2bf;
    font-size: 10px;
    padding: 2px 8px;
    border-radius: 4px;
    cursor: pointer;
    transition: all 0.2s;
}

.lumina-code-copy:hover, .lumina-code-edit:hover {
    background: #abb2bf;
    color: #21252b;
}

/* Enhanced Copy button — looks more like a real button */
.lumina-code-copy {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #2563eb;
    border: 1px solid #2563eb;
    color: #ffffff;
    font-size: 11px;
    font-weight: 600;
    padding: 4px 12px;
    border-radius: 5px;
    cursor: pointer;
    transition: all 0.15s ease;
    font-family: 'Inter', system-ui, sans-serif;
    line-height: 1;
    box-shadow: 0 1px 2px rgba(37, 99, 235, 0.2);
}

.lumina-code-copy:hover {
    background: #1d4ed8;
    border-color: #1d4ed8;
    color: #ffffff;
    box-shadow: 0 2px 6px rgba(37, 99, 235, 0.4);
    transform: translateY(-1px);
}

.lumina-code-copy:active {
    transform: translateY(0);
    box-shadow: 0 1px 2px rgba(37, 99, 235, 0.2);
}

.lumina-code-copy svg {
    width: 12px;
    height: 12px;
    flex-shrink: 0;
}

.lumina-code-content {
    margin: 0;
    padding: 16px;
    color: #abb2bf;
    background-color: #282c34;
    white-space: pre-wrap; 
    word-break: break-word;
    overflow-x: hidden;
    outline: none;
    font-size: 13px;
    line-height: 1.6;
    border: none;
    font-family: 'Fira Code', 'Consolas', monospace;
    cursor: default; 
}

/* Modal Code Textarea */
.lumina-code-textarea {
    width: 100%;
    height: 100%;
    flex: 1;
    background-color: #282c34;
    color: #abb2bf;
    font-family: 'Fira Code', 'Consolas', monospace;
    padding: 16px;
    border: none;
    outline: none;
    font-size: 13px;
    resize: none;
    box-sizing: border-box;
}

/* Syntax Highlighting Tokens */
.token-kwd { color: #c678dd; font-weight: bold; } 
.token-str { color: #98c379; } 
.token-num { color: #d19a66; } 
.token-com { color: #5c6370; font-style: italic; } 
.token-tag { color: #e06c75; } 
.token-attr { color: #d19a66; } 
.token-val { color: #98c379; } 
.token-op { color: #56b6c2; } 
.token-type { color: #e5c07b; } 
.token-var { color: #e06c75; } 
.token-def { color: #61afef; } 

/* --- END CODE BLOCK --- */

.lumina-editor pre {
    background-color: #1e293b;
    color: #f1f5f9;
    padding: 16px;
    border-radius: 8px;
    font-family: 'Fira Code', 'Consolas', monospace;
    font-size: 14px;
    overflow-x: auto;
    margin: 20px 0;
    line-height: 1.5;
}

.lumina-code-content {
    border-radius: 0;
    margin: 0;
}

.lumina-todo-item {
    display: flex;
    align-items: center;
    margin-bottom: 4px;
}

.lumina-modal input[type="checkbox"],
.lumina-bubble-settings input[type="checkbox"],
.lumina-todo-item input[type="checkbox"],
input[type="checkbox"] {
    appearance: none;
    -webkit-appearance: none;
    width: 16px;
    height: 16px;
    margin-right: 8px;
    cursor: pointer;
    background-color: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 3px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    position: relative;
    flex-shrink: 0;
}

input[type="checkbox"]:checked {
    background-color: #2563eb !important;
    border-color: #2563eb !important;
}

input[type="checkbox"]:checked::after {
    content: '';
    width: 4px;
    height: 8px;
    border: solid white;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
    position: absolute;
    top: 2px;
}

.lumina-source-wrapper { flex: 1; background: #272822; position: relative; overflow: hidden; min-height: 500px; display: flex; flex-direction: column; }
.lumina-source-toolbar { padding: 6px 12px; background: #1e293b; border-bottom: 1px solid #334155; display: flex; align-items: center; gap: 8px; color: #94a3b8; font-size: 12px; font-weight: 600; z-index: 15; }
.lumina-source-toolbar .lumina-select { background: #0f172a; border-color: #334155; color: #e2e8f0; height: 26px; font-size: 12px; }
.lumina-cm-host { flex: 1; position: relative; overflow: hidden; }
.CodeMirror { height: 100% !important; font-family: 'Fira Code', 'Consolas', monospace; z-index: 10; font-size: 14px; min-height: 500px; background: #272822; color: #f8f8f2; }
.cm-s-monokai.CodeMirror { background: #272822; color: #f8f8f2; }
.CodeMirror-gutters { background: #272822; border-right: 1px solid #3b3b32; }
.CodeMirror-linenumber { color: #75715e; }
.le-textarea { width: 100%; height: 100%; background: #272822; color: #f8f8f2; padding: 15px; border: none; resize: none; font-family: 'Fira Code', monospace; outline: none; line-height: 1.5; box-sizing: border-box; word-break: break-all; }

.lumina-img-selected { 
    outline: 2px solid #3b82f6 !important; 
    position: relative; 
    z-index: 10; 
    transition: outline 0.1s ease;
}

/* Floating Toolbars - CRITICAL: High Z-Index to stay above fullscreen editor (99999) */
.lumina-image-toolbar, 
.lumina-video-toolbar, 
.lumina-column-toolbar,
.lumina-bubble,
.lumina-block-toolbar, 
.lumina-button-toolbar, 
.lumina-map-toolbar,
.lumina-table-toolbar {
    position: absolute;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 4px 8px;
    display: flex;
    gap: 4px;
    z-index: 100000 !important; /* > 99999 */
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.lumina-bubble-settings {
    z-index: 100005 !important;
    position: absolute;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    width: 240px;
}

.lumina-bubble-settings .lbs-row {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
}

.lumina-bubble-settings label {
    width: 80px;
    font-size: 12px;
    color: #000000;
}

.lumina-bubble-settings .lumina-btn {
    color: #000000;
}

.lumina-bubble-settings input[type="range"] {
    flex: 1;
    height: 4px;
    -webkit-appearance: none;
    background: #e2e8f0;
    border-radius: 2px;
    outline: none;
}

.lumina-bubble-settings input[type="range"]::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 14px;
    height: 14px;
    background: #3b82f6;
    border-radius: 50%;
    cursor: pointer;
}

.lumina-resizer { 
    position: absolute; 
    width: 12px; 
    height: 12px; 
    background: #3b82f6; 
    border: 2px solid #ffffff; 
    border-radius: 50%; 
    z-index: 100000 !important; /* > Fullscreen */
    box-shadow: 0 0 0 1px rgba(0,0,0,0.2);
    cursor: move;
}

.lumina-resizer.nw { top: -6px; left: -6px; cursor: nwse-resize; }
.lumina-resizer.ne { top: -6px; right: -6px; cursor: nesw-resize; }
.lumina-resizer.sw { bottom: -6px; left: -6px; cursor: nesw-resize; }
.lumina-resizer.se { bottom: -6px; right: -6px; cursor: nwse-resize; }
.lumina-resizer.n { top: -6px; left: 50%; margin-left: -6px; cursor: ns-resize; }
.lumina-resizer.s { bottom: -6px; left: 50%; margin-left: -6px; cursor: ns-resize; }
.lumina-resizer.e { top: 50%; right: -6px; margin-top: -6px; cursor: ew-resize; }
.lumina-resizer.w { top: 50%; left: -6px; margin-top: -6px; cursor: ew-resize; }
.lumina-img-selected { outline: 2px solid #3b82f6; cursor: move; }

.lumina-free-box {
    transition: box-shadow 0.2s, outline 0.2s;
    overflow: visible;
    max-width: 95%; 
    box-sizing: border-box; 
    word-wrap: break-word;
}
.lumina-free-box img, .lumina-free-box video, .lumina-free-box iframe {
    max-width: 100% !important;
}

.lumina-box-selected {
    outline: 2px solid #3b82f6;
    z-index: 10 !important; 
}
.lumina-box-drag-handle {
    position: absolute;
    top: -16px;
    left: 0;
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    padding: 2px 4px;
    cursor: move;
    z-index: 100000 !important;
    display: none; 
    align-items: center;
    justify-content: center;
    color: #000000;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.lumina-box-selected .lumina-box-drag-handle {
    display: flex;
}
.lumina-box-drag-handle svg {
    display: block;
}

@keyframes luminaFadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes luminaSlideUp { from { opacity: 0; transform: translateY(20px) scale(0.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
@keyframes luminaPulse { 0% { transform: scale(1); } 50% { transform: scale(1.05); } 100% { transform: scale(1); } }
@keyframes luminaSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

.lumina-anim-fade { animation: luminaFadeIn 0.8s ease-out forwards; }
.lumina-anim-slide { animation: luminaSlideUp 0.6s ease-out forwards; }
.lumina-anim-pulse { animation: luminaPulse 2s infinite; }
.lumina-anim-spin { animation: luminaSpin 10s linear infinite; }
.lumina-anim-slide-left { animation: luminaSlideLeft 0.8s ease-out forwards; }
.lumina-anim-slide-right { animation: luminaSlideRight 0.8s ease-out forwards; }
.lumina-anim-slide-down { animation: luminaSlideDown 0.8s ease-out forwards; }
.lumina-anim-zoom-in { animation: luminaZoomIn 0.6s ease-out forwards; }
.lumina-anim-zoom-out { animation: luminaZoomOut 0.6s ease-out forwards; }
.lumina-anim-flip { animation: luminaFlip 0.8s ease-out forwards; }
.lumina-anim-bounce { animation: luminaBounce 0.8s ease-out forwards; }
.lumina-anim-shake { animation: luminaShake 0.5s ease-out; }
.lumina-anim-glow { animation: luminaGlow 2s ease-in-out infinite; }
@keyframes luminaSlideLeft { from { opacity:0; transform: translateX(-60px); } to { opacity:1; transform: translateX(0); } }
@keyframes luminaSlideRight { from { opacity:0; transform: translateX(60px); } to { opacity:1; transform: translateX(0); } }
@keyframes luminaSlideDown { from { opacity:0; transform: translateY(-40px); } to { opacity:1; transform: translateY(0); } }
@keyframes luminaZoomIn { from { opacity:0; transform: scale(0.5); } to { opacity:1; transform: scale(1); } }
@keyframes luminaZoomOut { from { opacity:0; transform: scale(1.5); } to { opacity:1; transform: scale(1); } }
@keyframes luminaFlip { from { opacity:0; transform: perspective(600px) rotateY(90deg); } to { opacity:1; transform: perspective(600px) rotateY(0deg); } }
@keyframes luminaBounce { 0% { opacity:0; transform: translateY(-100px); } 60% { opacity:1; transform: translateY(15px); } 80% { transform: translateY(-10px); } 100% { transform: translateY(0); } }
@keyframes luminaShake { 0%,100% { transform: translateX(0); } 25% { transform: translateX(-10px); } 75% { transform: translateX(10px); } }
@keyframes luminaGlow { 0%,100% { box-shadow: 0 0 5px rgba(99,102,241,0.3); } 50% { box-shadow: 0 0 20px rgba(99,102,241,0.6); } }

.lumina-anim-typewriter {
    display: inline-block;
    vertical-align: bottom;
    white-space: pre-wrap;
    /* Do not force single line or width */
}

.lumina-tw-char {
    opacity: 0;
    animation: luminaRevealChar 0.05s forwards;
    display: inline;
}

@keyframes luminaRevealChar {
    to { opacity: 1; }
}

/* ================================================================== */
/* TEXT APPEARANCE ANIMATIONS                                         */
/* ================================================================== */
/* These are applied to <span data-custom-style> elements in the      */
/* editor and on the frontend. Each one is a one-shot "forwards"       */
/* animation so the text stays in its final state.                    */

@keyframes luminaTextBlurIn {
    from { opacity: 0; filter: blur(12px); transform: scale(0.95); }
    to   { opacity: 1; filter: blur(0);    transform: scale(1); }
}
@keyframes luminaTextGlowPulse {
    0%   { opacity: 0; text-shadow: 0 0 0 transparent; }
    50%  { opacity: 1; text-shadow: 0 0 20px rgba(99,102,241,0.8); }
    100% { opacity: 1; text-shadow: 0 0 8px rgba(99,102,241,0.4); }
}
@keyframes luminaTextWave {
    0%   { opacity: 0; transform: translateY(20px) rotate(-4deg); }
    50%  { opacity: 1; transform: translateY(-6px) rotate(2deg); }
    100% { opacity: 1; transform: translateY(0) rotate(0); }
}
@keyframes luminaTextRise {
    from { opacity: 0; transform: translateY(40px); }
    to   { opacity: 1; transform: translateY(0); }
}
@keyframes luminaTextShimmer {
    0%   { opacity: 0; background-position: -200% center; }
    50%  { opacity: 1; }
    100% { opacity: 1; background-position: 200% center; }
}
@keyframes luminaTextNeon {
    0%   { opacity: 0; text-shadow: 0 0 0 currentColor, 0 0 0 currentColor; }
    50%  { opacity: 1; text-shadow: 0 0 8px currentColor, 0 0 16px currentColor; }
    100% { opacity: 1; text-shadow: 0 0 4px currentColor, 0 0 10px currentColor; }
}
@keyframes luminaTextStretch {
    0%   { opacity: 0; transform: scaleY(0.3) scaleX(1.4); }
    60%  { opacity: 1; transform: scaleY(1.15) scaleX(0.9); }
    100% { opacity: 1; transform: scaleY(1) scaleX(1); }
}
@keyframes luminaTextFlipIn {
    from { opacity: 0; transform: perspective(400px) rotateX(-90deg); transform-origin: bottom; }
    to   { opacity: 1; transform: perspective(400px) rotateX(0); transform-origin: bottom; }
}
@keyframes luminaTextWobble {
    0%   { opacity: 0; transform: translateX(-40px) rotate(-8deg); }
    50%  { opacity: 1; transform: translateX(8px) rotate(3deg); }
    75%  { transform: translateX(-4px) rotate(-1deg); }
    100% { opacity: 1; transform: translateX(0) rotate(0); }
}
@keyframes luminaTextDropIn {
    0%   { opacity: 0; transform: translateY(-60px) scale(1.2); }
    60%  { opacity: 1; transform: translateY(8px) scale(0.95); }
    100% { opacity: 1; transform: translateY(0) scale(1); }
}
@keyframes luminaTextGradientShift {
    0%   { opacity: 0; background-position: 0% center; }
    100% { opacity: 1; background-position: 200% center; }
}

/* Northern Lights (Aurora) — animated multi-color gradient sweep */
@keyframes luminaTextAurora {
    0%   { opacity: 0; background-position: 0% center; filter: hue-rotate(0deg); }
    30%  { opacity: 1; }
    50%  { filter: hue-rotate(60deg); }
    100% { opacity: 1; background-position: 200% center; filter: hue-rotate(120deg); }
}

/* Text animation utility classes */
.lumina-anim-text-blur-in    { display: inline-block; animation: luminaTextBlurIn      0.9s ease-out forwards; }
.lumina-anim-text-glow       { display: inline-block; animation: luminaTextGlowPulse   1.2s ease-out forwards; }
.lumina-anim-text-wave       { display: inline-block; animation: luminaTextWave        0.9s ease-out forwards; }
.lumina-anim-text-rise       { display: inline-block; animation: luminaTextRise        0.8s ease-out forwards; }
.lumina-anim-text-shimmer    { display: inline-block; animation: luminaTextShimmer     1.6s linear forwards;
                                background: linear-gradient(90deg, #94a3b8 0%, #2563eb 50%, #94a3b8 100%);
                                background-size: 200% auto; -webkit-background-clip: text;
                                background-clip: text; -webkit-text-fill-color: transparent; }
.lumina-anim-text-neon       { display: inline-block; animation: luminaTextNeon        1.4s ease-out forwards; }
.lumina-anim-text-stretch    { display: inline-block; animation: luminaTextStretch     0.8s ease-out forwards; }
.lumina-anim-text-flip-in    { display: inline-block; animation: luminaTextFlipIn      0.8s ease-out forwards; }
.lumina-anim-text-wobble     { display: inline-block; animation: luminaTextWobble      1.0s ease-out forwards; }
.lumina-anim-text-drop       { display: inline-block; animation: luminaTextDropIn      0.9s cubic-bezier(.34,1.56,.64,1) forwards; }
.lumina-anim-text-gradient   { display: inline-block; animation: luminaTextGradientShift 2s linear forwards;
                                background: linear-gradient(90deg, #6366f1, #ec4899, #f59e0b, #6366f1);
                                background-size: 200% auto; -webkit-background-clip: text;
                                background-clip: text; -webkit-text-fill-color: transparent; }
.lumina-anim-text-aurora     { display: inline-block; animation: luminaTextAurora      3s ease-in-out forwards;
                                background: linear-gradient(90deg, #00ff87, #60efff, #0061ff, #60efff, #00ff87, #a855f7, #00ff87);
                                background-size: 200% auto; -webkit-background-clip: text;
                                background-clip: text; -webkit-text-fill-color: transparent; }
.lumina-anim-text-typewriter { display: inline-block; vertical-align: bottom; white-space: pre-wrap; }

/* LOOP modifier — when .lumina-anim-loop is present on the same element,
   the animation runs forever instead of stopping after one pass.
   We achieve this by overriding animation-iteration-count to infinite.
   The original duration & timing-function set by the per-effect class
   are preserved. */
.lumina-anim-loop { animation-iteration-count: infinite !important; }

/* PAUSED modifier — set by lumina-anim-observer.js on elements that are
   below the fold. The animation is frozen until the user scrolls to it. */
.lumina-anim-paused { animation-play-state: paused !important; }

.lumina-active-block { outline: 2px dashed #2563eb; position: relative; }
.lumina-layout-block { box-sizing: border-box; width: 100%; }

.lumina-drop-top {
    border-top: 4px solid #2563eb !important;
}
.lumina-drop-bottom {
    border-bottom: 4px solid #2563eb !important;
}
.lumina-dragging {
    opacity: 0.5;
    background-color: #f8fafc;
}

.lumina-block-toolbar { flex-direction: column; align-items: stretch; width: 220px; }
.lumina-block-toolbar .lbt-row-actions { display: flex; gap: 4px; justify-content: space-between; }
.lumina-block-toolbar .lbt-row-actions button { flex: 1; }
.lumina-block-settings { position: absolute; left: 100%; top: 0; margin-left: 8px; background: #fff; z-index: 100005 !important; }
.lumina-button-toolbar { flex-direction: column; width: 240px; padding: 12px; gap: 8px; }

.lbt-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.lbt-row label { font-size: 11px; font-weight: 600; color: #64748b; }
.lbt-row input[type=text], .lbt-row input[type=number] { border: 1px solid #e2e8f0; border-radius: 4px; padding: 4px; font-size: 12px; width: 100%; box-sizing: border-box; }
.lbt-row input[type=color] { width: 30px; height: 24px; border: none; background: transparent; cursor: pointer; padding: 0; }
.lbt-actions { display: flex; gap: 8px; margin-top: 4px; }
.lbt-actions button { flex: 1; padding: 6px; font-size: 12px; border-radius: 4px; cursor: pointer; border: 1px solid #e2e8f0; font-weight: 500; }
.lbt-update { background: #2563eb; color: white; border-color: #2563eb; }
.lbt-delete { background: #fee2e2; color: #ef4444; border-color: #fecaca; }
.lbt-close { background: white; color: #64748b; }
.lumina-btn-txt { font-size: 11px; font-weight: 600; color: #2563eb; width: auto; padding: 4px 8px; border: 1px solid transparent; background: transparent; border-radius: 6px; cursor: pointer; }

/* Force Black Icons and Text in Settings */
.lumina-bubble-settings svg, 
.lumina-bubble-settings svg path, 
.lumina-bubble-settings svg rect, 
.lumina-bubble-settings svg circle, 
.lumina-bubble-settings svg line, 
.lumina-bubble-settings svg polyline, 
.lumina-bubble-settings svg polygon {
    stroke: #000000 !important;
    color: #000000 !important;
}

.lumina-bubble-settings .lumina-btn {
    color: #000000 !important;
}
.lumina-bubble-settings input, 
.lumina-bubble-settings select,
.lumina-bubble-settings textarea {
    color: #000000 !important;
    background-color: #ffffff;
}

.lbs-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #f1f5f9; font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; }
.lbs-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; font-size: 13px; color: #000000; }
.lbs-row:last-child { margin-bottom: 0; }
.lbs-row label { font-size: 11px; font-weight: 600; color: #000000; text-transform: uppercase; min-width: 60px; } 
.lbs-row input[type=number] { width: 60px; border: 1px solid #e2e8f0; padding: 4px; font-size: 12px; border-radius: 4px; background: #ffffff; color: #000000; } 
.lbs-row input[type=range] { flex: 1; margin: 0 8px; } 
.lbs-grp { margin-bottom: 12px; } 
.lbs-grp span { display: block; font-size: 11px; font-weight: 600; color: #000000; margin-bottom: 6px; text-transform: uppercase; } 
.lbs-shapes { display: flex; gap: 6px; flex-wrap: wrap; } 
.lbs-shapes button { flex: 1; border: 1px solid #e2e8f0; background: #fff; border-radius: 6px; cursor: pointer; padding: 6px; min-width: 32px; display:flex; justify-content:center; align-items:center; font-size: 14px; transition: all 0.2s; color: #000000; } 
.lbs-shapes button:hover { border-color: #3b82f6; background-color: #eff6ff; color: #2563eb; }
.lis-flip-x, .lis-flip-y { font-size: 11px !important; font-weight: 500; padding: 6px 12px !important; }

.lumina-dropzone { border: 2px dashed #cbd5e1; border-radius: 8px; padding: 30px 20px; text-align: center; background: #f8fafc; transition: all 0.2s; cursor: pointer; margin-bottom: 20px; }
.lumina-dropzone:hover, .lumina-dropzone.dragover { border-color: #3b82f6; background: #eff6ff; }
.lumina-dropzone-icon { font-size: 32px; margin-bottom: 10px; color: #64748b; }
.lumina-dropzone-text { font-size: 14px; color: #475569; font-weight: 500; }
.lumina-dropzone-input { display: none; }
.lumina-tabs { display: flex; border-bottom: 1px solid #e2e8f0; margin-bottom: 20px; }
.lumina-tab { padding: 10px 20px; background: transparent; border: none; border-bottom: 2px solid transparent; cursor: pointer; font-size: 14px; font-weight: 500; color: #64748b; transition: all 0.2s; }
.lumina-tab:hover { color: #1e293b; background: #f8fafc; }
.lumina-tab.active { color: #2563eb; border-bottom-color: #2563eb; background: transparent; }
.lumina-tab-content { display: none; animation: fadeIn 0.2s; }
.lumina-tab-content.active { display: block; }

.lumina-color-picker { position: absolute; z-index: 100010 !important; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); width: 220px; display: flex; flex-direction: column; gap: 10px; animation: fadeIn 0.15s ease-out; }
.lcp-grid { display: grid; grid-template-columns: repeat(10, 1fr); gap: 4px; }
.lcp-swatch { width: 18px; height: 18px; border-radius: 3px; cursor: pointer; border: 1px solid rgba(0,0,0,0.1); transition: transform 0.1s; }
.lcp-swatch:hover { transform: scale(1.3); border-color: #3b82f6; z-index: 2; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
.lcp-row { display: flex; gap: 8px; align-items: center; }
.lcp-hex { flex: 1; padding: 6px 8px; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 12px; font-family: 'Fira Code', monospace; text-transform: uppercase; color: #334155; }
.lcp-reset { padding: 6px 12px; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 11px; font-weight: 500; color: #64748b; cursor: pointer; transition: all 0.2s; }

/* PREVIEW AREA */
.lumina-preview-area { 
    display: flex; 
    flex-direction: column; 
    background: #e2e8f0; 
    flex: 1; 
    overflow: hidden; 
    position: relative; 
    width: 100%; 
    min-height: 500px;
    z-index: 2000; /* Must cover everything */
}

/* When in preview mode, the wrapper needs a defined height so the
   preview area can fill it. We use flex: 1 on the preview area instead
   of absolute positioning, which avoids the "narrow strip" bug when
   the editor is hidden. */
.lumina-wrapper.lumina-preview-active {
    min-height: 600px;
}

.lumina-preview-toolbar { 
    padding: 10px; 
    display: flex; 
    justify-content: center; 
    align-items: center;
    gap: 10px; 
    background: #fff; 
    border-bottom: 1px solid #e2e8f0; 
    flex-shrink: 0;
}

.lumina-preview-toolbar button { 
    padding: 8px; 
    border: 1px solid #e2e8f0; 
    background: #fff; 
    border-radius: 6px; 
    cursor: pointer; 
    color: #000000; 
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
}

.lumina-preview-toolbar button.active { 
    background: #eff6ff; 
    border-color: #3b82f6; 
    color: #2563eb; 
}

.lumina-preview-toolbar .lp-close {
    width: auto;
    padding: 0 16px;
    font-size: 13px;
    font-weight: 500;
}

.lumina-preview-frame { 
    flex: 1; 
    overflow-y: auto; 
    overflow-x: hidden;
    padding: 40px 20px; 
    display: flex; 
    justify-content: center; 
    align-items: flex-start; 
    background: #e2e8f0;
    min-height: 0; /* allow flex child to shrink */
}

.lumina-preview-frame .lp-content { 
    background: #fff; 
    box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1); 
    transition: width 0.4s cubic-bezier(0.16, 1, 0.3, 1), min-height 0.4s; 
    box-sizing: border-box; 
    flex: none !important; 
    height: auto !important; 
    overflow: visible !important; 
    min-height: 800px; 
    margin-bottom: 40px; 
    position: relative; 
}

/* Preview iframe — fills the lp-content area */
.lumina-preview-frame .lp-content iframe {
    width: 100%;
    height: 100%;
    min-height: 800px;
    border: none;
    display: block;
    background: #fff;
}

.lumina-preview-frame .lp-content img { 
    max-width: 100% !important; 
    height: auto !important; 
    display: block; 
}

/* Loading indicator for preview */
.lp-loading {
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    color: #64748b;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    background: #fff;
    padding: 16px 24px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    z-index: 10;
}

/* === DEVICE SIZES === */

/* Desktop: Full width up to container max */
.lumina-preview-frame.desktop .lp-content { 
    width: 100%; 
    max-width: 1200px; 
    padding: 0;
}

/* Tablet Portrait (iPad) */
.lumina-preview-frame.tablet.portrait .lp-content { 
    width: 768px !important; 
    min-height: 1024px !important; 
    border-radius: 20px; 
    border: 12px solid #334155;
    padding: 0; 
    overflow: hidden;
}

/* Tablet Landscape */
.lumina-preview-frame.tablet.landscape .lp-content { 
    width: 1024px !important; 
    min-height: 768px !important; 
    border-radius: 20px; 
    border: 12px solid #334155;
    padding: 0; 
    overflow: hidden;
}

/* Mobile Portrait (Approx iPhone 14/Pixel) */
.lumina-preview-frame.mobile.portrait .lp-content { 
    width: 375px !important; 
    min-height: 812px !important;
    border-radius: 40px; 
    border: 12px solid #1e293b; 
    border-top-width: 30px; /* Notch area simulation */
    border-bottom-width: 30px;
    padding: 0; 
    overflow: hidden;
}

/* Mobile Landscape */
.lumina-preview-frame.mobile.landscape .lp-content { 
    width: 812px !important; 
    min-height: 375px !important;
    border-radius: 40px; 
    border: 12px solid #1e293b; 
    border-left-width: 30px; 
    border-right-width: 30px;
    padding: 0; 
    overflow: hidden;
}

/* --- END PREVIEW --- */

.lumina-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100020 !important; display: flex; justify-content: center; align-items: center; backdrop-filter: blur(2px); animation: luminaFadeIn 0.2s ease; }
.lumina-modal { background: #fff; width: 500px; border-radius: 12px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); max-height: 90vh; display: flex; flex-direction: column; animation: luminaSlideUp 0.25s cubic-bezier(0.16,1,0.3,1); }
.lumina-modal-header { padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; font-weight: 600; color: #1e293b; font-size: 16px; }
.lumina-modal-header-left { display: flex; align-items: center; gap: 8px; }
.lumina-modal-header-icon { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; color: #2563eb; }
.lumina-modal-header-icon svg { width: 18px; height: 18px; stroke: currentColor; stroke-width: 2; fill: none; }
.lumina-modal-title { margin: 0; font-size: 16px; font-weight: 600; color: #1e293b; }
.lumina-modal-body { padding: 20px; overflow-y: auto; flex: 1; }
.lumina-modal-footer { padding: 16px 20px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 8px; background: #fff; }
.lumina-btn-primary { background: #2563eb; color: #fff; padding: 8px 20px; border-radius: 6px; border: none; font-weight: 500; cursor: pointer; transition: background 0.2s; font-size: 14px; }
.lumina-btn-primary:hover { background: #1d4ed8; }
.lumina-btn-secondary { background: #f1f5f9; color: #334155; padding: 8px 20px; border-radius: 6px; border: 1px solid #e2e8f0; font-weight: 500; cursor: pointer; transition: background 0.2s, border-color 0.2s; font-size: 14px; }
.lumina-btn-secondary:hover { background: #e2e8f0; border-color: #cbd5e1; }
.x { background: none; border: none; font-size: 24px; line-height: 1; cursor: pointer; color: #94a3b8; transition: color 0.2s; } .x:hover { color: #ef4444; }
.lumina-modal-close { padding: 0 8px; }

/* Active toolbar button (set by modules/activeState.js) */
.lumina-btn-active { background:#eff6ff !important; color:#2563eb !important; border-color:#3b82f6 !important; }

.lumina-modal input, 
.lumina-modal select, 
.lumina-modal textarea {
    background-color: #ffffff !important;
    color: #000000 !important;
}

.lumina-ai-modal { width: 600px; }
.lumina-ai-header-bar { background: linear-gradient(to right, #7c3aed, #4f46e5); padding: 16px; display: flex; justify-content: space-between; align-items: center; color: white; }
.lah-title { display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 16px; }
.lah-close { background: transparent; border: none; color: white; font-size: 20px; cursor: pointer; opacity: 0.8; }
.lumina-ai-tabs { display: flex; border-bottom: 1px solid #e2e8f0; }
.lumina-ai-tab { flex: 1; padding: 12px; border: none; background: transparent; border-bottom: 2px solid transparent; font-weight: 500; color: #64748b; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; font-size: 14px; }
.lumina-ai-tab.active { border-color: #4f46e5; color: #4f46e5; }
.lumina-ai-tab:hover:not(.active) { background: #f8fafc; }
.lumina-ai-content { padding: 20px; max-height: 400px; overflow-y: auto; }
.lumina-ai-pane { display: none; } .lumina-ai-pane.active { display: block; }
.lumina-ai-tip { background: #eff6ff; border: 1px solid #dbeafe; color: #1e40af; padding: 12px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
.lumina-ai-info { border: 1px solid; padding: 16px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
.lumina-ai-info.purple { background: #f3e8ff; border-color: #e9d5ff; color: #6b21a8; } .lumina-ai-info.indigo { background: #e0e7ff; border-color: #c7d2fe; color: #3730a3; }
.lumina-ai-info h4 { font-weight: 700; margin: 0 0 4px 0; display: flex; align-items: center; gap: 6px; }
.lumina-ai-preview { background: #f8fafc; border: 1px solid #e2e8f0; padding: 10px; border-radius: 6px; font-style: italic; font-size: 13px; color: #475569; margin-bottom: 16px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.lumina-ai-label { font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; margin-bottom: 6px; display: block; }
.lumina-ai-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px; }
.lumina-ai-card { display: flex; align-items: center; gap: 10px; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; background: white; cursor: pointer; text-align: left; transition: all 0.2s; }
.lumina-ai-card:hover { border-color: #a5b4fc; background: #eef2ff; }
.lac-icon { width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; }
.lumina-ai-card.purple .lac-icon { background: #f3e8ff; color: #9333ea; } .lumina-ai-card.indigo .lac-icon { background: #e0e7ff; color: #4f46e5; }
.lumina-ai-card.blue .lac-icon { background: #dbeafe; color: #2563eb; } .lumina-ai-card.emerald .lac-icon { background: #d1fae5; color: #059669; }
.lac-text { font-weight: 500; color: #334155; font-size: 14px; }
.lumina-ai-input-group { display: flex; gap: 8px; }
.lumina-ai-input-group input { flex: 1; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; outline: none; font-size: 14px; background-color: #ffffff; color: #000000; }
.lumina-ai-go { background: #7c3aed; color: white; border: none; border-radius: 8px; width: 44px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.lumina-ai-loader { margin-top: 10px; color: #6366f1; font-weight: 500; font-size: 14px; display: flex; align-items: center; gap: 8px; justify-content: center; }
.lumina-toggle { position: relative; display: inline-block; width: 40px; height: 20px; }
.lumina-toggle input { opacity: 0; width: 0; height: 0; }
.lumina-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .4s; border-radius: 20px; }
.lumina-slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 2px; bottom: 2px; background-color: white; transition: .4s; border-radius: 50%; }
input:checked + .lumina-slider { background-color: #2563eb; } input:checked + .lumina-slider:before { transform: translateX(20px); }
.lumina-block-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; padding: 10px; }
.lumina-block-item { border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; cursor: pointer; transition: all 0.2s; background: #fff; text-align: left; display: flex; flex-direction: column; min-height: 120px; }
.lumina-block-item:hover { border-color: #3b82f6; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); transform: translateY(-2px); }
.lumina-block-item h4 { margin: 8px 0 4px 0; font-size: 14px; font-weight: 600; color: #1e293b; }
.lbi-desc { font-size: 12px; color: #64748b; line-height: 1.4; margin: 0; }
.lbi-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 5px; }
.lbi-header small { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; color: #64748b; text-transform: uppercase; }
.lbi-icon { width: 36px; height: 36px; background: #f1f5f9; color: #64748b; border-radius: 8px; display: flex; align-items: center; justify-content: center; }
.lumina-block-item:hover .lbi-icon { background: #eff6ff; color: #2563eb; }
.lumina-lib-tabs { display: flex; padding: 0 16px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; overflow-x: auto; }
.lumina-lib-tab { padding: 12px 16px; border: none; background: transparent; border-bottom: 2px solid transparent; font-weight: 500; color: #64748b; cursor: pointer; font-size: 13px; white-space: nowrap; }
.lumina-lib-tab:hover { color: #1e293b; background: rgba(0,0,0,0.02); }
.lumina-lib-tab.active { border-color: #2563eb; color: #2563eb; }
.lumina-lib-content { padding: 16px; overflow-y: auto; background: #f8fafc; flex: 1; }
.lumina-block-empty { padding: 20px; text-align: center; color: #94a3b8; grid-column: 1/-1; }
.lumina-lib-anim-bar { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
.lumina-lib-anim-label { font-size: 12px; color: #475569; font-weight: 600; margin: 0; }
.lumina-lib-anim-bar .lumina-select { flex: 1; max-width: 240px; padding: 4px 8px; font-size: 12px; }
.lumina-lib-anim-hint { font-size: 11px; color: #94a3b8; }
.lbi-category { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; color: #64748b; text-transform: uppercase; }

/* === Module Picker modal === */
.lumina-mod-picker { padding: 4px; }
.lumina-mod-search { width: 100%; background-color: #ffffff; color: #1e293b; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 10px; font-size: 13px; box-sizing: border-box; outline: none; margin-bottom: 12px; transition: border-color 0.15s; }
.lumina-mod-search:focus { border-color: #2563eb; }
.lumina-mod-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; max-height: 60vh; overflow-y: auto; }
.lumina-mod-item { display: flex; gap: 10px; padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; cursor: pointer; background: #fff; transition: all 0.15s; }
.lumina-mod-item:hover { border-color: #2563eb; background: #eff6ff; }
.lumina-mod-icon { flex-shrink: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; background: #eff6ff; color: #2563eb; border-radius: 6px; }
.lumina-mod-info { flex: 1; min-width: 0; }
.lumina-mod-title { font-size: 13px; font-weight: 600; color: #1e293b; margin-bottom: 2px; }
.lumina-mod-desc { font-size: 11px; color: #64748b; line-height: 1.4; }
.lumina-mod-code { font-size: 10px; color: #94a3b8; margin-top: 4px; font-family: monospace; }
.lumina-mod-badge-db { display: inline-block; margin-left: 6px; padding: 1px 6px; font-size: 10px; background: #fef3c7; color: #92400e; border-radius: 4px; font-weight: 600; }
.lumina-mod-empty { grid-column: 1/-1; padding: 24px; text-align: center; color: #94a3b8; }

/* === Settings modal labels & inputs (used by coreModals.js + buttonCreator.js) === */
/* These replace hardcoded color:#000 / background:#fff inline styles so that
   dark-theme rules can override them via the body.dark-theme selector below. */
.lumina-settings-label { font-size: 14px; color: #1e293b; }
.lumina-settings-hint { font-size: 11px; color: #64748b; }
.lumina-settings-hint-inline { font-weight: normal; text-transform: none; color: #94a3b8; }
.lumina-modal-input { background-color: #ffffff; color: #1e293b; }
.lumina-modal-textarea { padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; resize: vertical; font-family: inherit; background-color: #ffffff; color: #1e293b; }

/* === Lumina grid selector button (emoji/special chars) === */
.lumina-grid-selector { display: grid; grid-template-columns: repeat(8, 1fr); gap: 5px; }
.lumina-grid-selector button { aspect-ratio: 1; border: 1px solid transparent; background: #fff; border-radius: 4px; font-size: 18px; cursor: pointer; transition: background 0.1s; color: #000000; }
.lumina-grid-selector button:hover { background: #f1f5f9; transform: scale(1.1); }

/* --- READ MORE / SPOILER --- */
details.lumina-details { margin: 16px 0; }
summary.lumina-summary { list-style: none; display: inline-flex; align-items: center; gap: 8px; padding: 8px 20px; background-color: #2563eb; color: #ffffff; font-size: 14px; font-weight: 500; border-radius: 9999px; cursor: pointer; user-select: none; transition: background-color 0.2s; }
summary.lumina-summary:hover { background-color: #1d4ed8; }
summary.lumina-summary::-webkit-details-marker { display: none; }
.lumina-details-content { margin-top: 16px; color: #334155; padding-left: 10px; border-left: 2px solid #e2e8f0; }
details.lumina-details .lumina-summary .read-less { display: none; }
details.lumina-details[open] .lumina-summary .read-more { display: none; }
details.lumina-details[open] .lumina-summary .read-less { display: inline; }
details.lumina-details:not([open]) .lumina-details-content { display: none; }

/* --- ACCORDION --- */
details.lumina-accordion { border: 1px solid #e2e8f0; margin-bottom: -1px; background: #fff; border-radius: 0; }
details.lumina-accordion:first-of-type { border-top-left-radius: 8px; border-top-right-radius: 8px; }
details.lumina-accordion:last-of-type { border-bottom-left-radius: 8px; border-bottom-right-radius: 8px; margin-bottom: 20px; }
summary.lumina-accordion-summary { list-style: none; padding: 12px 16px; background-color: #fff; cursor: pointer; font-weight: 600; color: #1e293b; position: relative; display: flex; justify-content: space-between; align-items: center; transition: background 0.2s; }
summary.lumina-accordion-summary:hover { background-color: #f8fafc; }
summary.lumina-accordion-summary::-webkit-details-marker { display: none; }
summary.lumina-accordion-summary::after { content: ''; width: 20px; height: 20px; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2394a3b8' stroke-width='2'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M19 9l-7 7-7-7'%3E%3C/path%3E%3C/svg%3E"); background-size: contain; background-repeat: no-repeat; transition: transform 0.2s; }
details.lumina-accordion[open] summary.lumina-accordion-summary::after { transform: rotate(180deg); }
.lumina-accordion-content { padding: 16px; border-top: 1px solid #e2e8f0; color: #475569; }

/* --- CUSTOM BUTTONS --- */
.lumina-custom-btn, .lumina-action-btn { display: inline-block; text-decoration: none; text-align: center; transition: all 0.2s ease; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05); cursor: pointer; }
.lumina-custom-btn:hover, .lumina-action-btn:hover { filter: brightness(95%); transform: translateY(-1px); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
.lumina-custom-btn:active, .lumina-action-btn:active { transform: translateY(0); }

/* --- TOGGLE BUTTON ("Читать далее / Свернуть") ---
   In the editor the toggle button looks identical to any other button
   created via the "Create Button" tool — no special background, no border,
   no hint text. The user can click it to open the standard button toolbar
   and edit text / colors / size. The only difference from a regular
   button is the [data-lumina-toggle] wrapper, which is invisible.
   The content that follows the button in the document will be hidden on
   the frontend (handled by assets/js/lumina-toggle.js). In the editor,
   that content remains fully visible and editable like normal content.
*/
.lumina-editor .lumina-toggle-block[data-lumina-toggle] {
    display: block;
    margin: 8px 0;
    background: transparent;
    border: none;
    padding: 0;
}
.lumina-editor .lumina-toggle-block[data-lumina-toggle] > .toggle-news-btn {
    /* Allow the button to be clicked and edited via the standard button
       toolbar (Create Button). Pointer events stay enabled so the editor's
       click handler in coreEvents.js can select the button. */
    pointer-events: auto;
    cursor: pointer;
}

@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } } @keyframes slideUp { from { transform: translateY(10px); opacity: 0; } to { transform: translateY(0); opacity: 1; } } @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
.lumina-spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid #6366f1; border-top-color: transparent; border-radius: 50%; animation: spin 1s linear infinite; }

/* Highlight for selected grid column */
.lumina-col-selected {
    outline: 2px dashed #2563eb !important;
    outline-offset: -2px;
    background-color: rgba(37, 99, 235, 0.05); /* Slight tint */
    position: relative;
    z-index: 10;
}

/* Quick Insert Menu - Replaces the old pill style */
.lumina-quick-wrapper {
    position: absolute;
    z-index: 100005 !important;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    animation: luminaFadeIn 0.2s ease-out;
}

.lumina-quick-trigger {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 4px;
    display: flex;
    gap: 4px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.lumina-quick-trigger .lumina-btn {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    background-color: transparent;
    color: #475569;
    transition: all 0.2s;
    border: none;
}

.lumina-quick-trigger .lumina-btn:hover {
    background-color: #f1f5f9;
    color: #0f172a;
}

/* Highlight the center ADD button slightly */
.lumina-quick-trigger .lumina-btn.active {
    color: #2563eb;
    background-color: #eff6ff;
}

/* Vertical Dropdown Menu */
.lumina-quick-dropdown {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 6px 0;
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    min-width: 140px;
    position: absolute;
    top: 45px; /* Just below trigger */
    left: 50%;
    transform: translateX(-50%);
    z-index: 100006 !important;
    animation: luminaSlideUp 0.1s ease-out;
}

.lumina-quick-item {
    padding: 8px 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: #334155;
    cursor: pointer;
    transition: background 0.1s;
}

.lumina-quick-item:hover {
    background-color: #f8fafc;
    color: #2563eb;
}

.lumina-quick-item svg {
    width: 16px;
    height: 16px;
    opacity: 0.7;
    stroke: currentColor;
}

.lumina-quick-item:hover svg {
    opacity: 1;
}

/* Ensure tooltips on quick insert don't get cut off */
.lumina-quick-insert .lumina-btn[title] {
    position: relative;
}

/* --- Button Sort List (Drag & Drop in Settings) --- */
.lumina-btn-sort-list {
    display: flex;
    flex-direction: column;
    gap: 2px;
    max-height: 420px;
    overflow-y: auto;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 4px;
    background: #f8fafc;
}

.lumina-btn-sort-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 7px 10px;
    background: #ffffff;
    border: 1px solid #f1f5f9;
    border-radius: 6px;
    cursor: default;
    user-select: none;
    transition: box-shadow 0.15s, border-color 0.15s, opacity 0.15s;
}

.lumina-btn-sort-item:hover {
    border-color: #cbd5e1;
}

.lumina-btn-sort-handle {
    font-size: 14px;
    color: #94a3b8;
    cursor: grab;
    line-height: 1;
    padding: 2px 4px;
    border-radius: 3px;
    transition: color 0.15s, background 0.15s;
    flex-shrink: 0;
}

.lumina-btn-sort-handle:hover {
    color: #475569;
    background: #f1f5f9;
}

.lumina-btn-sort-handle:active {
    cursor: grabbing;
}

.lumina-btn-sort-label {
    font-size: 13px;
    color: #334155;
    flex: 1;
    white-space: nowrap;
}

.lumina-btn-sort-dragging {
    opacity: 0.4;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
}

.lumina-btn-sort-over {
    border-top: 2px solid #2563eb !important;
    margin-top: -1px;
}

/* Context menu */
.lumina-context-menu { user-select: none; }
.lumina-context-menu-item { user-select: none; }

/* Minimap */
.lumina-minimap { opacity: 0.8; transition: opacity 0.2s; }
.lumina-minimap:hover { opacity: 1; }

/* ========================================
   DARK THEME — matches AICMS admin dark theme
   Colors: bg=#0f172a, card=#1e293b, border=#334155,
   text=#e2e8f0, muted=#94a3b8, accent=#3b82f6
   ======================================== */

body.dark-theme .lumina-container { background:#1e293b; border-color:#334155; }
body.dark-theme .lumina-toolbar { background:#0f172a; border-color:#334155; }
body.dark-theme .lumina-toolbar-placeholder { border-color:#334155; }
body.dark-theme .lumina-btn { color:#cbd5e1; }
body.dark-theme .lumina-btn:hover { background:#334155; color:#60a5fa; }
body.dark-theme .lumina-btn[data-cmd].active,
body.dark-theme .lumina-btn[data-btn-id].active,
body.dark-theme .lumina-btn[data-mode].active,
body.dark-theme .lumina-btn[data-id].active,
body.dark-theme .lumina-btn.lumina-btn-active { background:#1e3a5f !important; color:#60a5fa !important; }
body.dark-theme .lumina-btn-ai { background:#7c3aed; color:#fff; }
body.dark-theme .lumina-btn-ai:hover { background:#6d28d9; }
body.dark-theme .lumina-select { background:#0f172a; color:#e2e8f0; border-color:#475569; }
body.dark-theme .lumina-select option { background:#1e293b; color:#e2e8f0; }
body.dark-theme .lumina-sep { background:#334155; }

/* Editor content stays WHITE by default — only toolbar is dark.
   Use .lumina-editor-dark-mode class to enable dark editor background (toggle in settings).
   When .lumina-theme-view is active, theme CSS controls the appearance. */
body.dark-theme .lumina-container { background:#1e293b; }
body.dark-theme .lumina-editor:not(.lumina-theme-view) { background:#ffffff; color:#1e293b; }
body.dark-theme .lumina-editor:focus { outline-color:#3b82f6; }
body.dark-theme .lumina-placeholder { color:#94a3b8; }

/* Dark editor mode (toggled via settings) — applies dark background to content area */
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) { background:#1e293b; color:#e2e8f0; }
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) p,
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) li,
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) span,
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) div { color:#e2e8f0; }
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) h1,
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) h2,
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) h3,
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) h4,
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) h5,
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) h6 { color:#f1f5f9; }
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) a { color:#60a5fa; }
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) blockquote { border-left-color:#475569; background:#0f172a; color:#cbd5e1; }
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) pre { background:#0f172a; color:#e2e8f0; border-color:#334155; }
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) code { background:#334155; color:#fbbf24; }
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) table { border-color:#334155; }
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) th { background:#0f172a; color:#e2e8f0; border-color:#334155; }
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) td { border-color:#334155; color:#e2e8f0; }
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) hr { border-color:#334155; }
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) img { opacity:0.92; }
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) img:hover { opacity:1; }
body.dark-theme .lumina-editor.lumina-editor-dark-mode:not(.lumina-theme-view) .lumina-placeholder { color:#475569; }

/* Bubble menus */
body.dark-theme .lumina-bubble { background:#1e293b; border-color:#334155; box-shadow:0 4px 12px rgba(0,0,0,0.4); }
body.dark-theme .lumina-bubble .lumina-btn { color:#cbd5e1; }
body.dark-theme .lumina-bubble .lumina-btn:hover { background:#334155; }

/* Toolbars (image, table, box, etc.) */
body.dark-theme .lumina-image-toolbar,
body.dark-theme .lumina-table-toolbar,
body.dark-theme .lumina-box-toolbar,
body.dark-theme .lumina-button-toolbar,
body.dark-theme .lumina-map-toolbar,
body.dark-theme .lumina-code-toolbar { background:#1e293b; border-color:#334155; box-shadow:0 4px 12px rgba(0,0,0,0.4); }

/* Box settings */
body.dark-theme .lumina-bubble-settings { background:#1e293b; border-color:#334155; box-shadow:0 8px 24px rgba(0,0,0,0.5); }
body.dark-theme .lumina-bubble-settings .lbs-header { color:#e2e8f0; border-bottom-color:#334155; }
body.dark-theme .lumina-bubble-settings label { color:#94a3b8; }
body.dark-theme .lumina-bubble-settings input[type="text"],
body.dark-theme .lumina-bubble-settings input[type="number"],
body.dark-theme .lumina-bubble-settings select,
body.dark-theme .lumina-bubble-settings textarea { background:#0f172a; color:#e2e8f0; border-color:#475569; }
body.dark-theme .lumina-bubble-settings .lbs-grp > span { color:#94a3b8; border-bottom-color:#334155; }

/* Quick insert */
body.dark-theme .lumina-quick-wrapper { background:#1e293b; border-color:#334155; box-shadow:0 4px 12px rgba(0,0,0,0.4); }
body.dark-theme .lumina-quick-btn { color:#cbd5e1; }
body.dark-theme .lumina-quick-btn:hover { background:#334155; color:#60a5fa; }

/* Modals */
body.dark-theme .lumina-modal-overlay { background:rgba(0,0,0,0.6); }
body.dark-theme .lumina-modal { background:#1e293b; color:#e2e8f0; border-color:#334155; }
body.dark-theme .lumina-modal-header { border-bottom-color:#334155; background:#0f172a !important; }
body.dark-theme .lumina-modal-title { color:#e2e8f0; }
body.dark-theme .lumina-modal-body { color:#e2e8f0; }
body.dark-theme .lumina-modal-body label { color:#cbd5e1; }
body.dark-theme .lumina-modal-body p,
body.dark-theme .lumina-modal-body span:not(.lumina-mod-badge-db):not(.lumina-settings-hint-inline),
body.dark-theme .lumina-modal-body div,
body.dark-theme .lumina-modal-body li,
body.dark-theme .lumina-modal-body td,
body.dark-theme .lumina-modal-body small { color:#e2e8f0; }
body.dark-theme .lumina-modal-body h1,
body.dark-theme .lumina-modal-body h2,
body.dark-theme .lumina-modal-body h3,
body.dark-theme .lumina-modal-body h4,
body.dark-theme .lumina-modal-body h5,
body.dark-theme .lumina-modal-body h6 { color:#f1f5f9; }
body.dark-theme .lumina-modal-body strong { color:#f1f5f9; }
body.dark-theme .lumina-modal-body code { background:#0f172a; color:#fbbf24; padding:1px 4px; border-radius:3px; }
body.dark-theme .lumina-modal-body input[type="text"],
body.dark-theme .lumina-modal-body input[type="url"],
body.dark-theme .lumina-modal-body input[type="email"],
body.dark-theme .lumina-modal-body input[type="password"],
body.dark-theme .lumina-modal-body input[type="number"],
body.dark-theme .lumina-modal-body input[type="tel"],
body.dark-theme .lumina-modal-body textarea,
body.dark-theme .lumina-modal-body select { background:#0f172a; color:#e2e8f0; border-color:#475569; }
body.dark-theme .lumina-modal-body input::placeholder,
body.dark-theme .lumina-modal-body textarea::placeholder { color:#64748b; }
body.dark-theme .lumina-modal-footer { border-top-color:#334155; background:#1e293b !important; }
body.dark-theme .lumina-modal-close { color:#64748b; }
body.dark-theme .lumina-modal-close:hover { background:#334155; color:#e2e8f0; }

/* Word Count table */
body.dark-theme .lumina-wordcount-table th,
body.dark-theme .lumina-wordcount-table td { border-color:#334155; color:#e2e8f0; }
body.dark-theme .lumina-wordcount-table th { background:#0f172a; color:#cbd5e1; }

/* Hint boxes inside modals */
body.dark-theme .lumina-modal-hint-box { background:#0f172a; color:#94a3b8; border-color:#334155; }
body.dark-theme .lumina-modal-warn-box { background:#422006; color:#fbbf24; border-color:#92400e; }

/* Modal buttons in body (non-footer) */
body.dark-theme .lumina-modal-body button:not(.lumina-btn-primary):not(.lumina-btn-secondary):not(.lumina-btn):not(.lbi-anim) {
    background:#334155; color:#e2e8f0; border:1px solid #475569;
}
body.dark-theme .lumina-modal-body button:not(.lumina-btn-primary):not(.lumina-btn-secondary):not(.lumina-btn):not(.lbi-anim):hover {
    background:#475569;
}
body.dark-theme .lumina-btn-primary { background:#2563eb; color:#fff; border-color:#1d4ed8; }
body.dark-theme .lumina-btn-secondary { background:#334155; color:#e2e8f0; border-color:#475569; }

/* Tabs in modals */
body.dark-theme .lumina-tab { color:#94a3b8; }
body.dark-theme .lumina-tab.active { color:#60a5fa; border-bottom-color:#3b82f6; }
body.dark-theme .lumina-tab:hover { color:#cbd5e1; }

/* Settings modal */
body.dark-theme .lumina-settings-modal { background:#1e293b; }
body.dark-theme .lumina-settings-modal .lumina-toggle input + .lumina-slider { background:#334155; }
body.dark-theme .lumina-settings-modal .lumina-toggle input:checked + .lumina-slider { background:#3b82f6; }
body.dark-theme .lumina-settings-modal .lumina-tab-content { color:#e2e8f0; }

/* Dropzone */
body.dark-theme .lumina-dropzone { background:#0f172a; border-color:#475569; }
body.dark-theme .lumina-dropzone:hover,
body.dark-theme .lumina-dropzone.dragover { border-color:#3b82f6; background:#1e293b; }
body.dark-theme .lumina-dropzone-text { color:#64748b; }
body.dark-theme .lumina-dropzone-icon { filter:grayscale(0.3); }

/* Grid selector (emoji/chars) */
body.dark-theme .lumina-grid-selector { background:#1e293b; }
body.dark-theme .lumina-grid-selector button { color:#e2e8f0; background:#0f172a; border-color:#334155; }
body.dark-theme .lumina-grid-selector button:hover { background:#334155; }

/* Code block */
body.dark-theme .lumina-code-wrapper { border-color:#334155; }
body.dark-theme .lumina-code-header { background:#0f172a; border-bottom-color:#334155; }
body.dark-theme .lumina-code-header select { background:#1e293b; color:#e2e8f0; border-color:#475569; }
body.dark-theme .lumina-code-actions button { color:#cbd5e1; background:#334155; }
body.dark-theme .lumina-code-actions button:hover { background:#475569; }
body.dark-theme .lumina-code-content { background:#0f172a; color:#e2e8f0; }
body.dark-theme .lumina-code-textarea { background:#0f172a; color:#e2e8f0; }

/* Token colors for code highlighting (dark theme) */
body.dark-theme .token-kwd { color:#c084fc; }
body.dark-theme .token-str { color:#86efac; }
body.dark-theme .token-num { color:#fdba74; }
body.dark-theme .token-com { color:#64748b; }
body.dark-theme .token-tag { color:#fca5a5; }
body.dark-theme .token-attr { color:#fdba74; }
body.dark-theme .token-val { color:#86efac; }
body.dark-theme .token-op { color:#67e8f9; }
body.dark-theme .token-type { color:#fde68a; }
body.dark-theme .token-var { color:#fca5a5; }

/* Free box */
body.dark-theme .lumina-free-box { box-shadow:0 4px 6px rgba(0,0,0,0.3); }
body.dark-theme .lumina-box-selected { outline-color:#3b82f6; }
body.dark-theme .lumina-box-drag-handle { background:#1e293b; border-color:#334155; color:#cbd5e1; }

/* Resizers */
body.dark-theme .lumina-resizer { background:#3b82f6; border-color:#1e293b; }

/* Toast */
body.dark-theme .lumina-toast { background:#1e293b; color:#e2e8f0; border-color:#334155; box-shadow:0 8px 24px rgba(0,0,0,0.5); }

/* Source mode */
body.dark-theme .lumina-source-mode { background:#0f172a; }
body.dark-theme .lumina-source-toolbar { background:#1e293b; border-color:#334155; }
body.dark-theme .lumina-source-textarea { background:#0f172a; color:#e2e8f0; border-color:#334155; }

/* Preview area */
body.dark-theme .lumina-preview-area { background:#1e293b; }

/* Context menu */
body.dark-theme .lumina-context-menu { background:#1e293b; border-color:#334155; box-shadow:0 8px 24px rgba(0,0,0,0.5); }
body.dark-theme .lumina-context-menu-item { color:#e2e8f0; }
body.dark-theme .lumina-context-menu-item:hover { background:#334155; }

/* Button creator modal */
body.dark-theme .lumina-btn-style-row { border-color:#334155; }
body.dark-theme .lumina-btn-style-row label { color:#94a3b8; }

/* Block drag handle */
body.dark-theme .lumina-block-drag-handle { color:#475569; }
body.dark-theme .lumina-block-drag-handle:hover { color:#64748b; }

/* Sortable buttons in settings */
body.dark-theme .lumina-btn-sort-item { background:#0f172a; border-color:#334155; }
body.dark-theme .lumina-btn-sort-handle { color:#64748b; }
body.dark-theme .lumina-btn-sort-label { color:#e2e8f0; }

/* Fullscreen mode */
body.dark-theme .lumina-fullscreen { background:#0f172a; }

/* Minimap */
body.dark-theme .lumina-minimap { background:#1e293b; border-color:#334155; }

/* === Theme View mode === */
/* Theme CSS is injected via api_theme_css.php with scoped selectors */
/* Only minimum layout protection — theme controls all visual styling */
.lumina-editor.lumina-theme-view {
    min-height: 500px !important;
    overflow-y: auto !important;
    box-sizing: border-box !important;
    width: 100% !important;
}

/* Dark theme: fix hardcoded text colors.
   Previously this used a broad !important override on ALL modal-body
   text which (a) made coloured badges (БД, category) appear grey and
   (b) didn't help with elements that had inline background:#fff
   (white background + grey text = invisible).
   Now that inline color:#000 / background:#fff are removed from the
   JS modules, we override specific element classes instead. */
body.dark-theme .lumina-modal-body { color: #e2e8f0; }
body.dark-theme .lumina-modal-body label { color: #cbd5e1; }
body.dark-theme .lumina-modal-body p,
body.dark-theme .lumina-modal-body span:not(.lumina-mod-badge-db):not(.lumina-settings-hint-inline),
body.dark-theme .lumina-modal-body div { color: #e2e8f0; }

/* Settings modal labels & inputs — proper dark theme */
body.dark-theme .lumina-settings-label { color: #e2e8f0 !important; }
body.dark-theme .lumina-settings-hint { color: #94a3b8 !important; }
body.dark-theme .lumina-settings-hint-inline { color: #94a3b8 !important; }
body.dark-theme .lumina-modal-input { background-color: #0f172a !important; color: #e2e8f0 !important; border-color: #475569 !important; }
body.dark-theme .lumina-modal-textarea { background-color: #0f172a !important; color: #e2e8f0 !important; border-color: #475569 !important; }

/* Module picker dark theme */
body.dark-theme .lumina-mod-search { background-color: #0f172a !important; color: #e2e8f0 !important; border-color: #475569 !important; }
body.dark-theme .lumina-mod-item { background: #1e293b !important; border-color: #334155 !important; }
body.dark-theme .lumina-mod-item:hover { border-color: #2563eb !important; background: #0f172a !important; }
body.dark-theme .lumina-mod-icon { background: #1e3a5f !important; color: #60a5fa !important; }
body.dark-theme .lumina-mod-title { color: #e2e8f0 !important; }
body.dark-theme .lumina-mod-desc { color: #94a3b8 !important; }
body.dark-theme .lumina-mod-code { color: #64748b !important; }
body.dark-theme .lumina-mod-empty { color: #64748b !important; }

/* Block library dark theme */
body.dark-theme .lumina-block-item { background: #1e293b !important; border-color: #334155 !important; }
body.dark-theme .lumina-block-item:hover { border-color: #2563eb !important; }
body.dark-theme .lumina-block-item h4 { color: #e2e8f0 !important; }
body.dark-theme .lbi-desc { color: #94a3b8 !important; }
body.dark-theme .lbi-icon { background: #334155 !important; color: #94a3b8 !important; }
body.dark-theme .lumina-block-item:hover .lbi-icon { background: #1e3a5f !important; color: #60a5fa !important; }
body.dark-theme .lbi-category { background: #334155 !important; color: #94a3b8 !important; }
body.dark-theme .lumina-block-empty { color: #64748b !important; }

/* Animation bar + tabs + content background dark theme */
body.dark-theme .lumina-lib-anim-bar { background: #0f172a !important; border-bottom-color: #334155 !important; }
body.dark-theme .lumina-lib-anim-label { color: #94a3b8 !important; }
body.dark-theme .lumina-lib-anim-hint { color: #64748b !important; }
body.dark-theme .lumina-lib-tabs { background: #0f172a !important; border-bottom-color: #334155 !important; }
body.dark-theme .lumina-lib-tab { color: #94a3b8 !important; }
body.dark-theme .lumina-lib-tab:hover { color: #e2e8f0 !important; background: rgba(255,255,255,0.05) !important; }
body.dark-theme .lumina-lib-tab.active { color: #60a5fa !important; border-color: #60a5fa !important; }
body.dark-theme .lumina-lib-content { background: #0f172a !important; }

body.dark-theme .lumina-settings-modal span,
body.dark-theme .lumina-settings-modal div,
body.dark-theme .lumina-settings-modal p { color:#e2e8f0 !important; }
body.dark-theme .lumina-settings-modal label { color:#94a3b8 !important; }
body.dark-theme .lumina-bubble-settings span,
body.dark-theme .lumina-bubble-settings div,
body.dark-theme .lumina-bubble-settings p { color:#e2e8f0 !important; }
body.dark-theme .lumina-bubble-settings label { color:#94a3b8 !important; }
body.dark-theme .lumina-bubble-settings .lbs-row { color:#e2e8f0 !important; }
body.dark-theme .lumina-bubble-settings .lbs-row label { color:#94a3b8 !important; }
body.dark-theme .lumina-bubble-settings .lbs-grp span { color:#94a3b8 !important; }
body.dark-theme .lumina-bubble-settings .lbs-shapes button { background:#0f172a; color:#e2e8f0; border-color:#334155; }
body.dark-theme .lumina-bubble-settings .lbs-shapes button:hover { background:#334155; }
body.dark-theme .lumina-ai-input-group input { background:#0f172a; color:#e2e8f0; border-color:#475569; }
body.dark-theme .lumina-grid-selector button { background:#0f172a; color:#e2e8f0; border-color:#334155; }

/* Dark theme: tabs — make hover more contrast */
body.dark-theme .lumina-tab { color:#64748b; background:#0f172a; border:1px solid #334155; border-bottom:none; }
body.dark-theme .lumina-tab:hover { color:#e2e8f0; background:#1e293b; }
body.dark-theme .lumina-tab.active { color:#60a5fa; background:#1e293b; border-bottom-color:#1e293b; }

/* Dark theme: toggle switches */
body.dark-theme .lumina-toggle input + .lumina-slider { background:#334155; }
body.dark-theme .lumina-toggle input:checked + .lumina-slider { background:#3b82f6; }

/* Dark theme: button sort list */
body.dark-theme .lumina-btn-sort-item { background:#0f172a !important; border-color:#334155 !important; }
body.dark-theme .lumina-btn-sort-label { color:#e2e8f0 !important; }

/* Dark theme: checkbox/select fixes in settings */
body.dark-theme .lumina-settings-modal input[type="checkbox"] { accent-color:#3b82f6; }
body.dark-theme .lumina-settings-modal select { background:#0f172a; color:#e2e8f0; border-color:#475569; }
body.dark-theme .lumina-settings-modal select option { background:#1e293b; color:#e2e8f0; }

/* Dark theme: fix modal header background and title contrast */
body.dark-theme .lumina-modal-header { background:#0f172a !important; border-bottom-color:#334155 !important; }
body.dark-theme .lumina-modal-title { color:#f1f5f9 !important; }
body.dark-theme .lumina-modal-header-icon { color:#60a5fa; }

/* Dark theme: fix settings modal — all inline-styled elements */
body.dark-theme .lumina-settings-modal div[style*="background:#f8fafc"],
body.dark-theme .lumina-settings-modal div[style*="background: #f8fafc"],
body.dark-theme .lumina-settings-modal div[style*="background:#fff"],
body.dark-theme .lumina-settings-modal div[style*="background: #fff"] { background:#0f172a !important; border-color:#334155 !important; color:#e2e8f0 !important; }

/* Dark theme: info/hint boxes with light background */
body.dark-theme .lumina-settings-modal div[style*="color:#64748b"] { color:#94a3b8 !important; background:#0f172a !important; border-color:#334155 !important; }
body.dark-theme .lumina-settings-modal div[style*="color:#999"] { color:#64748b !important; }
body.dark-theme .lumina-settings-modal div[style*="color:#666"] { color:#64748b !important; }

/* Dark theme: fix all spans with color:#000000 or color:#000 */
body.dark-theme .lumina-settings-modal span[style*="color:#000000"],
body.dark-theme .lumina-settings-modal span[style*="color:#000"],
body.dark-theme .lumina-settings-modal span[style*="color: #000000"],
body.dark-theme .lumina-settings-modal span[style*="color: #000"] { color:#e2e8f0 !important; }

/* Dark theme: fix all divs with color:#000000 or color:#333 */
body.dark-theme .lumina-settings-modal div[style*="color:#000000"],
body.dark-theme .lumina-settings-modal div[style*="color:#000"],
body.dark-theme .lumina-settings-modal div[style*="color:#333"],
body.dark-theme .lumina-settings-modal div[style*="color: #000000"],
body.dark-theme .lumina-settings-modal div[style*="color: #000"],
body.dark-theme .lumina-settings-modal div[style*="color: #333"] { color:#e2e8f0 !important; }

/* Dark theme: language note info box */
body.dark-theme .lumina-settings-modal div[style*="font-size:12px;color:#64748b;background:#f8fafc"] {
    color:#94a3b8 !important; background:#0f172a !important; border-color:#334155 !important;
}

/* Dark theme: language label span */
body.dark-theme .lumina-settings-modal span[style*="font-size:14px;color:#000000"] { color:#e2e8f0 !important; }

/* Dark theme: selects with inline background:#fff */
body.dark-theme .lumina-settings-modal select[style*="background:#fff"],
body.dark-theme .lumina-settings-modal select[style*="background: #fff"] { background:#0f172a !important; color:#e2e8f0 !important; border-color:#475569 !important; }

/* Dark theme: all inputs with inline color:#000 */
body.dark-theme .lumina-settings-modal input[style*="color:#000"],
body.dark-theme .lumina-settings-modal input[style*="color:#000000"] { color:#e2e8f0 !important; background:#0f172a !important; border-color:#475569 !important; }

/* Dark theme: border colors on settings rows */
body.dark-theme .lumina-settings-modal div[style*="border:1px solid #f1f5f9"] { border-color:#334155 !important; }
body.dark-theme .lumina-settings-modal div[style*="border:1px solid #e2e8f0"] { border-color:#334155 !important; }
`;
