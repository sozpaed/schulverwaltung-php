document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-letter-editor]');
    const input = document.getElementById('inhalt');
    const editor = document.querySelector('[data-editor-content]');
    const controls = document.querySelector('[data-editor-controls]');
    if (!form || !input || !editor || !controls) return;

    const parsed = new DOMParser().parseFromString('<root>' + input.value + '</root>', 'application/xml');
    if (parsed.querySelector('parsererror') || parsed.querySelector('*:not(root):not(p):not(br):not(strong):not(em)')) return;
    function loadNode(source, parent) {
        if (source.nodeType === Node.TEXT_NODE) {
            parent.appendChild(document.createTextNode(source.nodeValue));
        } else if (source.nodeType === Node.ELEMENT_NODE) {
            const element = document.createElement(source.tagName);
            parent.appendChild(element);
            for (const child of source.childNodes) loadNode(child, element);
        }
    }
    for (const child of parsed.documentElement.childNodes) loadNode(child, editor);
    input.hidden = true;
    input.required = false;
    editor.hidden = false;
    controls.hidden = false;
    document.execCommand('defaultParagraphSeparator', false, 'p');

    controls.addEventListener('mousedown', (event) => {
        if (event.target.closest('button')) event.preventDefault();
    });
    controls.addEventListener('click', (event) => {
        const button = event.target.closest('[data-format]');
        if (button) {
            editor.focus();
            document.execCommand(button.dataset.format);
        }
    });
    editor.addEventListener('paste', (event) => {
        event.preventDefault();
        document.execCommand('insertText', false, event.clipboardData.getData('text/plain'));
    });
    form.addEventListener('submit', () => {
        const xml = document.implementation.createDocument('', 'root');
        function copy(source, parent) {
            if (source.nodeType === Node.TEXT_NODE) {
                parent.appendChild(xml.createTextNode(source.nodeValue));
            } else if (source.nodeType === Node.ELEMENT_NODE) {
                const names = { P: 'p', DIV: 'p', B: 'strong', STRONG: 'strong', I: 'em', EM: 'em', BR: 'br' };
                const name = names[source.tagName];
                const target = name ? xml.createElement(name) : parent;
                if (name) parent.appendChild(target);
                for (const child of source.childNodes) copy(child, target);
            }
        }
        for (const child of editor.childNodes) copy(child, xml.documentElement);
        const serialized = new XMLSerializer().serializeToString(xml.documentElement);
        input.value = serialized.replace(/^<root>|<\/root>$/g, '').replace(/^<root\/>$/, '');
    });
});
