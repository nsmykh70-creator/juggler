function initializeEditorHelpers(editorInstance) {
    const editor = editorInstance;
    const snippets = document.querySelectorAll('.snippet');
    const hiddenImageUploader = document.getElementById('snippetImageUploader');

    const handleSnippetClick = (event) => {
        event.preventDefault();
        const snippetElement = event.currentTarget;
        const action = snippetElement.dataset.action;
        if (action === 'uploadImage') {
            hiddenImageUploader.click();
            return;
        }
        const codeElement = snippetElement.querySelector('pre');
        if (codeElement) {
            insertCodeAtCursor(codeElement.textContent);
        }
    };
    
    const handleImageUpload = async (event) => {
        const file = event.target.files[0];
        if (!file) return;
        
        const formData = new FormData();
        formData.append('image', file);
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
        
        try {
            const response = await fetch('upload_image.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            if (result.status === 'success') {
                const imgTag = `<img src="${result.url}" alt="${file.name}" class="img-fluid">`;
                insertCodeAtCursor(imgTag);
            } else {
                console.error('Upload error:', result.message);
            }
        } catch (error) {
            console.error('Upload failed:', error);
        }
    };
    
    snippets.forEach(snippet => {
        snippet.addEventListener('click', handleSnippetClick);
    });
    
    hiddenImageUploader.addEventListener('change', handleImageUpload);
    
    function insertCodeAtCursor(code) {
        if (!editor) return;
        
        const position = editor.getPosition();
        const range = new monaco.Range(
            position.lineNumber,
            position.column,
            position.lineNumber,
            position.column
        );
        
        editor.executeEdits('snippet-inserter', [{
            range: range,
            text: code,
            forceMoveMarkers: true
        }]);
        
        editor.focus();
    }
}