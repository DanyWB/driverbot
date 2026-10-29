// Offline, dependency-free conversion of the supplied non-interlaced RGB(A) PNGs.
// This is deliberately not a general image editor and is never run by the bot.
const fs = require("node:fs");
const path = require("node:path");
const zlib = require("node:zlib");

const ROOT = path.join(__dirname, "../assets/contracts");
const LANGUAGES = ["ru", "en", "ua"];
const SIGNATURE = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);
const METADATA = new Set(["pHYs", "cHRM", "gAMA", "iCCP", "sRGB"]);

function crc32(data) {
  let crc = 0xffffffff;
  for (const byte of data) {
    crc ^= byte;
    for (let bit = 0; bit < 8; bit++) crc = (crc >>> 1) ^ ((crc & 1) ? 0xedb88320 : 0);
  }
  return (crc ^ 0xffffffff) >>> 0;
}

function chunk(type, data) {
  const result = Buffer.alloc(data.length + 12);
  result.writeUInt32BE(data.length);
  result.write(type, 4, 4, "ascii");
  data.copy(result, 8);
  result.writeUInt32BE(crc32(result.subarray(4, -4)), result.length - 4);
  return result;
}

function paeth(left, up, upperLeft) {
  const p = left + up - upperLeft;
  const a = Math.abs(p - left), b = Math.abs(p - up), c = Math.abs(p - upperLeft);
  return a <= b && a <= c ? left : b <= c ? up : upperLeft;
}

function decodePng(buffer) {
  if (!buffer.subarray(0, 8).equals(SIGNATURE)) throw new Error("Invalid PNG signature");
  const chunks = [];
  let offset = 8;
  while (offset < buffer.length) {
    if (offset + 12 > buffer.length) throw new Error("Truncated PNG chunk");
    const length = buffer.readUInt32BE(offset);
    const end = offset + 12 + length;
    if (end > buffer.length) throw new Error("Truncated PNG data");
    const type = buffer.toString("ascii", offset + 4, offset + 8);
    if (crc32(buffer.subarray(offset + 4, end - 4)) !== buffer.readUInt32BE(end - 4)) {
      throw new Error(`Invalid PNG checksum: ${type}`);
    }
    if (!["IHDR", "IDAT", "IEND"].includes(type) && !METADATA.has(type)) {
      throw new Error(`Unsupported PNG chunk: ${type}`);
    }
    chunks.push({type, data: buffer.subarray(offset + 8, end - 4)});
    offset = end;
  }
  const header = chunks[0]?.data;
  if (chunks[0]?.type !== "IHDR" || header.length !== 13 || chunks.at(-1)?.type !== "IEND") {
    throw new Error("Invalid PNG structure");
  }
  const width = header.readUInt32BE(0), height = header.readUInt32BE(4);
  const colorType = header[9];
  if (header[8] !== 8 || ![2, 6].includes(colorType) || header[10] || header[11] || header[12]) {
    throw new Error("Only 8-bit, non-interlaced RGB/RGBA PNGs are supported");
  }
  if (!width || !height || width * height > 100000000) throw new Error("Unsupported PNG dimensions");
  const channels = colorType === 6 ? 4 : 3;
  const stride = width * channels;
  const inflated = zlib.inflateSync(Buffer.concat(chunks.filter(c => c.type === "IDAT").map(c => c.data)), {
    maxOutputLength: (stride + 1) * height,
  });
  if (inflated.length !== (stride + 1) * height) throw new Error("Invalid PNG scanline length");
  const pixels = Buffer.alloc(stride * height);
  for (let y = 0; y < height; y++) {
    const filter = inflated[y * (stride + 1)];
    if (filter > 4) throw new Error(`Unsupported PNG filter: ${filter}`);
    for (let x = 0; x < stride; x++) {
      const i = y * stride + x;
      const left = x >= channels ? pixels[i - channels] : 0;
      const up = y > 0 ? pixels[i - stride] : 0;
      const upperLeft = y > 0 && x >= channels ? pixels[i - stride - channels] : 0;
      const prediction = [0, left, up, Math.floor((left + up) / 2), paeth(left, up, upperLeft)][filter];
      pixels[i] = (inflated[y * (stride + 1) + 1 + x] + prediction) & 255;
    }
  }
  return {width, height, channels, pixels, chunks};
}

function compositeWhite(image) {
  const {width, height, channels, pixels} = image;
  const rgb = Buffer.alloc(width * height * 3);
  for (let pixel = 0; pixel < width * height; pixel++) {
    const alpha = channels === 4 ? pixels[pixel * channels + 3] : 255;
    for (let color = 0; color < 3; color++) {
      rgb[pixel * 3 + color] = Math.round((pixels[pixel * channels + color] * alpha + 255 * (255 - alpha)) / 255);
    }
  }
  return rgb;
}

function encodeOpaquePng(image) {
  const header = Buffer.from(image.chunks[0].data);
  header[9] = 2; // RGB has no alpha channel and cannot render as transparent.
  const rgb = compositeWhite(image);
  const stride = image.width * 3;
  const scanlines = Buffer.alloc((stride + 1) * image.height);
  for (let y = 0; y < image.height; y++) rgb.copy(scanlines, y * (stride + 1) + 1, y * stride, (y + 1) * stride);
  return Buffer.concat([
    SIGNATURE,
    chunk("IHDR", header),
    ...image.chunks.filter(c => METADATA.has(c.type)).map(c => chunk(c.type, c.data)),
    chunk("IDAT", zlib.deflateSync(scanlines, {level: 9})),
    chunk("IEND", Buffer.alloc(0)),
  ]);
}

function prepareContracts({check = false, root = ROOT} = {}) {
  if (!check) fs.mkdirSync(path.join(root, "opaque"), {recursive: true});
  for (const lang of LANGUAGES) {
    const name = `agreement-${lang}.png`;
    const source = decodePng(fs.readFileSync(path.join(root, name)));
    const destination = path.join(root, "opaque", name);
    if (check) {
      const output = decodePng(fs.readFileSync(destination));
      if (source.width !== output.width || source.height !== output.height || output.channels !== 3 ||
          !output.pixels.equals(compositeWhite(source))) {
        throw new Error(`Opaque contract does not match its source: ${lang}`);
      }
      const metadata = image => image.chunks.filter(c => METADATA.has(c.type));
      if (JSON.stringify(metadata(source)) !== JSON.stringify(metadata(output))) {
        throw new Error(`Contract metadata changed: ${lang}`);
      }
    } else {
      fs.writeFileSync(destination, encodeOpaquePng(source));
    }
    console.log(`${check ? "Verified" : "Prepared"} ${name}: ${source.width}x${source.height}, opaque white RGB`);
  }
}

if (require.main === module) {
  const args = process.argv.slice(2);
  if (args.some(arg => arg !== "--check")) throw new Error("Usage: node prepare-contracts.js [--check]");
  prepareContracts({check: args.includes("--check")});
}

module.exports = {compositeWhite, decodePng, encodeOpaquePng, prepareContracts};
