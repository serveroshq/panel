/** Longest side models read images at; bigger only costs more. */
const MAX_SIDE = 1568;

const MAX_BYTES = 3_500_000;

/** Shrinks an image to what a model reads, as a data URL. PNGs stay PNG (logos keep their see-through parts) unless that's too big. */
export async function imageForAi(file: File): Promise<string> {
    if (!file.type.startsWith('image/')) {
        throw new Error('not an image');
    }
    const bitmap = await createImageBitmap(file);
    const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));
    const context = canvas.getContext('2d');
    if (!context) throw new Error('no canvas');

    if (file.type === 'image/png') {
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        const png = canvas.toDataURL('image/png');
        if (png.length * 0.75 < MAX_BYTES) return png;
    }

    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();

    return canvas.toDataURL('image/jpeg', 0.86);
}
