async function renderDiagrams() {
    for (const code of document.querySelectorAll('pre > code.language-mermaid')) {
        const diagram = document.createElement('pre');
        diagram.className = 'mermaid';
        diagram.textContent = code.textContent;
        code.parentElement.replaceWith(diagram);
    }
    const diagrams = [...document.querySelectorAll('.mermaid')];
    if (!diagrams.length) return;
    const showError = (diagram, source, error) => {
        console.error('Mermaid diagram could not be rendered:', error);
        diagram.classList.add('mermaid-error');
        const message = document.createElement('p');
        message.setAttribute('role', 'alert');
        message.textContent = 'Unable to render this diagram. Source is shown below.';
        const code = document.createElement('pre');
        code.textContent = source;
        diagram.replaceChildren(message, code);
    };
    try {
        await new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = '/assets/vendor/mermaid/mermaid.min.js';
            script.onload = resolve;
            script.onerror = () => reject(new Error('Could not load the local Mermaid library.'));
            document.head.append(script);
        });
    } catch (error) {
        for (const diagram of diagrams) showError(diagram, diagram.textContent, error);
        return;
    }
    mermaid.initialize({ startOnLoad: false, securityLevel: 'strict', suppressErrorRendering: true, theme: 'neutral' });
    for (const [index, diagram] of diagrams.entries()) {
        const source = diagram.textContent;
        try {
            const { svg, bindFunctions } = await mermaid.render(`dbnet-diagram-${index}`, source);
            diagram.innerHTML = svg;
            bindFunctions?.(diagram);
            diagram.classList.add('mermaid-rendered');
        } catch (error) {
            showError(diagram, source, error);
        }
    }
}

renderDiagrams();
