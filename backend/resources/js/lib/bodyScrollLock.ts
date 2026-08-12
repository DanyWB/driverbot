export function lockBodyScroll(targetDocument: Document): () => void {
    const { body } = targetDocument;
    const previousOverflow = body.style.overflow;

    body.style.overflow = 'hidden';

    return () => {
        body.style.overflow = previousOverflow;
    };
}
