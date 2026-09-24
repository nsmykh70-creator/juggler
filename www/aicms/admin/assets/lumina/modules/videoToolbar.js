export function initVideoToolbar(editor) {
    editor.videoToolbar = document.createElement('div');
    editor.videoToolbar.className = 'lumina-video-toolbar lumina-image-toolbar';
    editor.videoToolbar.style.display = 'none';

    const buttons = [
        { id: 'settings', icon: 'settings', title: 'Change Video URL' },
        { id: 'trash', icon: 'trash', title: 'Delete Video' }
    ];

    buttons.forEach(btn => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'lumina-btn';
        button.innerHTML = editor.getIcon(btn.icon);
        button.title = btn.title;
        
        button.onmousedown = (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (!editor.selectedImage) return;

            if (btn.id === 'trash') {
                editor.selectedImage.remove();
                editor.deselectAll();
                editor.sync();
            } else if (btn.id === 'settings') {
                openVideoSettings(editor);
            }
        };
        editor.videoToolbar.appendChild(button);
    });

    document.body.appendChild(editor.videoToolbar);
}

function openVideoSettings(editor) {
    const h = `
         <div class="lbs-row">
            <label style="width:auto;margin-right:10px;">Video/Embed URL:</label>
            <input type="text" id="vid-update-url" class="lumina-select" style="flex:1" placeholder="YouTube, Vimeo, etc.">
        </div>
    `;
    
    let currentSrc = '';
    const iframe = editor.selectedImage.querySelector('iframe, video');
    if (iframe) currentSrc = iframe.src;

    const modalBody = editor.createModalOverlay('Update Video', h, [{
        text: 'Update',
        onClick: (e, modal) => {
            const url = modal.querySelector('#vid-update-url').value;
            if(url) {
                updateVideoSource(editor, editor.selectedImage, url);
            }
            modal.closest('.lumina-modal-overlay').remove();
        }
    }]);
    
    setTimeout(() => {
        modalBody.querySelector('#vid-update-url').value = currentSrc;
    }, 50);
}

function updateVideoSource(editor, wrapper, url) {
    let embed = '';
    if (url.match(/(youtube\.com|youtu\.be)/)) {
        const id = url.match(/v=([^&]+)/) ? url.match(/v=([^&]+)/)[1] : url.split('/').pop();
        embed = `<iframe src="https://www.youtube.com/embed/${id}" frameborder="0" allowfullscreen style="width:100%;height:100%;pointer-events:none;"></iframe>`;
    } else if (url.match(/vimeo\.com/)) {
        const id = url.split('/').pop();
        embed = `<iframe src="https://player.vimeo.com/video/${id}" frameborder="0" allowfullscreen style="width:100%;height:100%;pointer-events:none;"></iframe>`;
    } else {
         embed = `<video src="${url}" controls style="width:100%;height:100%;pointer-events:none;"></video>`;
    }
    wrapper.innerHTML = embed;
    editor.sync();
}
