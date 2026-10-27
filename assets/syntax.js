async function highlightCode() {
    const blocks = [...document.querySelectorAll('pre > code')]
        .filter(code => !code.matches('.language-mermaid, .nohighlight, .no-highlight')
            && !code.closest('.mermaid'));
    if (!blocks.length) return;
    const theme = document.createElement('link');
    theme.rel = 'stylesheet';
    theme.href = '/assets/vendor/highlight/github.min.css';
    document.head.append(theme);
    try {
        await new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = '/assets/vendor/highlight/highlight.min.js';
            script.onload = resolve;
            script.onerror = () => reject(new Error('Could not load the local syntax highlighter.'));
            document.head.append(script);
        });
    } catch (error) {
        console.error('Code syntax highlighting failed:', error);
        return;
    }
    for (const code of blocks) {
        const language = [...code.classList].find(name => name.startsWith('language-'))?.slice(9) || 'auto';
        if (['text', 'plaintext', 'txt'].includes(language)) continue;
        if (language !== 'auto' && !hljs.getLanguage(language)) {
            console.warn(`No syntax highlighter registered for "${language}"; leaving code as plain text.`);
            continue;
        }
        try {
            const source = code.textContent;
            const result = language === 'auto'
                ? hljs.highlightAuto(source, ['javascript', 'xml', 'css', 'bash', 'json', 'php', 'perl', 'applescript'])
                : hljs.highlight(source, { language, ignoreIllegals: true });
            code.innerHTML = result.value;
            code.classList.add('hljs');
        } catch (error) {
            console.error('Code block could not be highlighted:', error);
        }
    }
}

highlightCode();
