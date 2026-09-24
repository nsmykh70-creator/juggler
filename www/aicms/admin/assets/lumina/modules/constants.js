export const FONT_FAMILY_OPTIONS = [
    'Inter', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Nunito', 'Raleway',
    'Poppins', 'Quicksand', 'Fira Sans', 'Work Sans',
    'Merriweather', 'Playfair Display', 'Lora', 'Crimson Text', 'Libre Baskerville',
    'Oswald', 'Anton', 'Pacifico', 'Dancing Script', 'Lobster', 'Caveat',
    'Fira Code', 'Space Mono'
];

export const EMOJI_LIST = [
    '😀', '😃', '😄', '😁', '😆', '😅', '😂', '🤣', '😊', '😇', '🙂', '🙃', '😉', '😌', '😍', '🥰', '😘', '😙', '😚',
    '😋', '😛', '😝', '😜', '🤪', '🤨', '🧐', '🤓', '😎', '🤩', '🥳', '😏', '😒', '😞', '😔', '😟', '😕', '🙁', '☹️', '😣',
    '😖', '😫', '😩', '🥺', '😢', '😭', '😤', '😠', '😡', '🤬', '🤯', '😳', '🥵', '🥶', '😱', '😨', '🤔', '😥', '😓', '🤗',
    '🤔', '🤭', '🤫', '🤥', '😶', '😐', '😑', '😬', '🙄', '😯', '😦', '😧', '😮', '😲', '🥱', '😴', '🤤', '😪', '😵', '🤐',
    '🥴', '🤢', '🤮', '😷', '🤒', '🤕', '🤑', '🤠', '😈', '👿', '👹', '👺', '🤡', '💩', '👻',
    '💀', '☠️', '👽', '👾', '🤖', '🎃', '😺', '😺', '😹', '😻', '😼', '😽', '🙀', '😿', '😾', '🙈', '🙉', '🙉', '🙊',
    '👋', '🤚', '✋', '🖖', '👌', '🤏', '✌️', '🤞', '🤟', '🤘', '🤙', '👈', '👉', '👉', '👆', '🖕', '👇', '☝️', '👍', '👎',
    '✊', '👊', '🤛', '🤜', '👏', '🙌', '👐', '🤲', '🤝', '🙏', '✍️', '💅', '🤳', '💪', '🦾', '🦿', '🦵', '🦶',
    '❤️', '🧡', '💛', '💚', '💙', '💜', '🤎', '🖤', '🤍', '💔', '❣️', '💕', '💞', '💓', '💗', '💖', '💘', '💝',
    '🔥', '✨', '🌟', '💫', '💥', '💢', '💦', '💧', '💤', '💨', '👂', '👀', '👁️', '🧠', '🦴', '🦷', '🗣️', '👤', '👥'
];

export const SPECIAL_CHARACTERS = [
    '©', '®', '™', '€', '£', '¥', '¢', '§', '¶', '°', '±', '≠', '≈', '×', '÷',
    '∞', 'µ', 'α', 'β', 'π', 'Ω', '∑', '√', '∫', '←', '↑', '→', '↓', '↔', '▲', '▼',
    '★', '♥', '♦', '♣', '♠', '♪', '♫', '☺', '☹', '✓', '✗', '«', '»', '—', '–', '…',
    '‰', '§', '№', '†', '‡', '•', '‣', '❛', '❜', '❝', '❞', '™', '℠', '®', '©', '℗',
    '⅛', '¼', '⅜', '½', '⅝', '¾', '⅞', '¹', '²', '³', '⁴', '⁵', '⁶', '⁷', '⁸', '⁹', '⁰'
];

export const COLOR_PALETTE = [
    '#000000', '#434343', '#666666', '#999999', '#b7b7b7', '#cccccc', '#d9d9d9', '#efefef', '#f3f3f3', '#ffffff',
    '#980000', '#ff0000', '#ff9900', '#ffff00', '#00ff00', '#00ffff', '#4a86e8', '#0000ff', '#9900ff', '#ff00ff',
    '#e6b8af', '#f4cccc', '#fce5cd', '#fff2cc', '#d9ead3', '#d0e0e3', '#c9daf8', '#cfe2f3', '#d9d2e9', '#ead1dc',
    '#dd7e6b', '#ea9999', '#f9cb9c', '#ffe599', '#b6d7a8', '#a2c4c9', '#a4c2f4', '#9fc5e8', '#b4a7d6', '#d5a6bd',
    '#cc4125', '#e06666', '#f6b26b', '#ffd966', '#93c47d', '#76a5af', '#6d9eeb', '#6fa8dc', '#8e7cc3', '#c27ba0',
    '#a61c00', '#cc0000', '#e69138', '#f1c232', '#6aa84f', '#45818e', '#3c78d8', '#3d85c6', '#674ea7', '#a64d79',
    '#85200c', '#990000', '#b45f06', '#bf9000', '#38761d', '#134f5c', '#1155cc', '#0b5394', '#351c75', '#741b47',
    '#5b0f00', '#660000', '#783f04', '#7f6000', '#274e13', '#0c343d', '#1c4587', '#073763', '#20124d', '#4c1130'
];

export const DEFAULT_MODULE_SETTINGS = {
    modules: {
        ai: true,
        images: true,
        video: true,
        tables: true,
        code: true,
        emoji: true,
        blocks: true,
        files: true
    },
    buttons: {
        // Core Editing
        undo: true, redo: true,
        bold: true, italic: true, underline: true, strike: true,
        superscript: true, subscript: true,
        clearFormat: true,
        fixSpacing: true, spellCheck: true,
        
        // Blocks & Layout
        quote: true, codeBlock: true, checklist: true,
        ul: true, ol: true,
        alignLeft: true, alignCenter: true, alignRight: true,
        separator: true, section: true,
        
        // Insertions
        link: true, anchor: true,
        image: true, video: true, 
        table: true, box: true,
        emoji: true, specialChars: true,
        tooltip: true, blocks: true, createButton: true,
        toggleButton: true,
        insertModule: true,
        
        // Styling
        textColor: true, highlightColor: true,
        letterSpacing: true, kerning: true,
        
        // Utils & View
        duplicate: true, wordCount: true,
        sourceCode: true, preview: true, fullscreen: true, settings: true
    },
    bubbleMenus: {
        text: true,
        image: true,
        block: true,
        box: true,
        quickInsert: true,
        codeBlock: true
    },
    stickyToolbar: true
};

export const DEFAULT_BUTTON_ORDER = [
    // History
    'undo', 'redo',
    // Text Formatting
    'bold', 'italic', 'underline', 'strike', 'superscript', 'subscript',
    'fixSpacing', 'spellCheck', 'clearFormat',
    // Blocks
    'quote', 'codeBlock', 'checklist', 'ul', 'ol',
    // Alignment
    'alignLeft', 'alignCenter', 'alignRight',
    // Insertions
    'separator', 'link', 'anchor', 'image', 'video', 'table', 'box', 'section',
    // Colors
    'textColor', 'highlightColor',
    // Special
    'emoji', 'specialChars', 'tooltip', 'blocks', 'createButton', 'toggleButton', 'insertModule',
    // Utils
    'duplicate', 'wordCount', 'formatHTML',
    // View
    'history', 'sourceCode', 'preview', 'themeView', 'fullscreen', 'settings'
];

// Buttons rendered as selects with fixed toolbar position (not draggable)
export const SELECT_BUTTONS = ['letterSpacing', 'kerning'];

export default {
    FONT_FAMILY_OPTIONS,
    EMOJI_LIST,
    SPECIAL_CHARACTERS,
    COLOR_PALETTE,
    DEFAULT_MODULE_SETTINGS,
    DEFAULT_BUTTON_ORDER,
    SELECT_BUTTONS
};
