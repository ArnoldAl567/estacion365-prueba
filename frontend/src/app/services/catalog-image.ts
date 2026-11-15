/** Prepara fotografías pequeñas sin enviar el original a servicios externos. */
export async function optimizeCatalogPhoto(file: File): Promise<Blob> {
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
    throw new Error('Sube una imagen JPG, PNG o WebP.');
  }
  if (file.size > 5 * 1024 * 1024) throw new Error('La imagen no debe superar 5 MB.');
  const bitmap = await createImageBitmap(file);
  try {
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d');
    if (!context) throw new Error('Este navegador no pudo preparar la fotografía.');
    for (const maxSide of [1200, 960, 720, 560]) {
      const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
      canvas.width = Math.max(1, Math.round(bitmap.width * scale));
      canvas.height = Math.max(1, Math.round(bitmap.height * scale));
      context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      for (const quality of [0.78, 0.62, 0.48]) {
        const blob = await new Promise<Blob | null>((resolve) =>
          canvas.toBlob(resolve, 'image/webp', quality),
        );
        if (!blob || blob.type !== 'image/webp')
          throw new Error('Este navegador no pudo preparar la fotografía.');
        if (blob.size <= 180 * 1024) return blob;
      }
    }
    throw new Error('La fotografía tiene demasiado detalle. Prueba con otra imagen.');
  } finally {
    bitmap.close();
  }
}
