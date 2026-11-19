/** Upload limit (nginx/PHP allow 2 MB per request). Kept in sync with ReferenceImageController::MAX_KILOBYTES. */
export const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;

/** Longest side after downsizing: plenty for a reference image. */
const MAX_SIDE = 2048;

/**
 * Downsize an image in the browser so it fits the upload limit: longest side
 * at most 2048px, re-encoded as JPEG (transparency becomes white). Images
 * already small enough, and GIFs (to keep animation), are sent unchanged.
 */
export async function shrinkForUpload(file: File): Promise<File> {
    if (file.type === 'image/gif') return file;

    const bitmap = await createImageBitmap(file);
    const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));

    if (scale === 1 && file.size <= MAX_UPLOAD_BYTES) {
        bitmap.close();
        return file;
    }

    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    const ctx = canvas.getContext('2d')!;
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();

    let blob: Blob | null = null;
    for (const quality of [0.9, 0.8, 0.7, 0.6]) {
        blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
        if (blob && blob.size <= MAX_UPLOAD_BYTES) break;
    }

    if (!blob) return file;

    return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
}
