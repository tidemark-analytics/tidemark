// Prepares a picked image for upload as a profile photo or organization logo: a centered square, 256 px, so files stay tiny.
export async function squareImage(file, { size = 256, type = 'image/jpeg' } = {}) {
  let bmp;
  try { bmp = await createImageBitmap(file); } catch (e) { throw new Error('That file could not be read as an image. Use a PNG, JPEG or WebP picture.'); }
  const s = Math.min(bmp.width, bmp.height);
  const canvas = document.createElement('canvas');
  canvas.width = canvas.height = size;
  const ctx = canvas.getContext('2d');
  if (type === 'image/jpeg') { ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, size, size); } // JPEG has no transparency
  ctx.drawImage(bmp, (bmp.width - s) / 2, (bmp.height - s) / 2, s, s, 0, 0, size, size);
  return new Promise((resolve, reject) => canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('Could not prepare that image.'))), type, 0.9));
}
