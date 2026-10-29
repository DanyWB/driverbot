const assert = require("node:assert/strict");
const {createHash} = require("node:crypto");
const fs = require("node:fs");
const path = require("node:path");
const test = require("node:test");
const {compositeWhite, decodePng, encodeOpaquePng} = require("../scripts/prepare-contracts");

const ROOT = path.join(__dirname, "../assets/contracts");
const ORIGINAL_HASHES = {
  ru: "dbd3f04cf0f5cd466d0d7dee12fa104f66ab8f420c637a7777fe2ef0a3f037a8",
  en: "d89fd77600bc6834df4dbf78b453847f3d1635e6c43a454e05827f572f825630",
  ua: "1aae90d3a9596e75c7861090c738c3a855bdc4862869601e4208ee19414dd734",
};

for (const [lang, hash] of Object.entries(ORIGINAL_HASHES)) {
  test(`${lang} contract is an unchanged-size white opaque composite of the original first page`, () => {
    const original = fs.readFileSync(path.join(ROOT, `agreement-${lang}.png`));
    assert.equal(createHash("sha256").update(original).digest("hex"), hash, "original must stay unchanged");
    const source = decodePng(original);
    const output = decodePng(fs.readFileSync(path.join(ROOT, "opaque", `agreement-${lang}.png`)));
    assert.deepEqual([source.width, source.height, source.channels], [2324, 3297, 4]);
    assert.deepEqual([output.width, output.height, output.channels], [2324, 3297, 3], "RGB cannot have transparent pixels");
    assert.deepEqual(
      output.chunks.filter(c => c.type === "pHYs"),
      source.chunks.filter(c => c.type === "pHYs"),
      "original physical resolution must stay unchanged"
    );

    let transparent = 0, opaque = 0, antialiased = 0, mismatches = 0;
    for (let pixel = 0; pixel < source.width * source.height; pixel++) {
      const alpha = source.pixels[pixel * 4 + 3];
      if (alpha === 0) transparent++;
      else if (alpha === 255) opaque++;
      else antialiased++;
      for (let channel = 0; channel < 3; channel++) {
        // Independent integer formulation of straight-alpha white compositing.
        const expected = Math.floor((source.pixels[pixel * 4 + channel] * alpha + 255 * (255 - alpha) + 127) / 255);
        if (output.pixels[pixel * 3 + channel] !== expected) mismatches++;
      }
    }
    assert.equal(mismatches, 0, "every text, antialias and background pixel must match the original over white");
    assert.ok(transparent > 0, "source contains the transparent background being fixed");
    assert.ok(opaque > 0, "source has unchanged opaque content");
    assert.ok(antialiased > 0, "edge antialiasing is covered by the pixel comparison");
  });
}

test("white compositing preserves opaque colors and correctly blends transparent edges", () => {
  const image = {width: 3, height: 1, channels: 4, pixels: Buffer.from([
    23, 45, 67, 0, 10, 20, 30, 255, 0, 100, 200, 128,
  ])};
  assert.deepEqual([...compositeWhite(image)], [255, 255, 255, 10, 20, 30, 127, 177, 227]);
  const header = Buffer.alloc(13);
  header.writeUInt32BE(image.width, 0);
  header.writeUInt32BE(image.height, 4);
  header[8] = 8;
  header[9] = 6;
  image.chunks = [{type: "IHDR", data: header}];
  const generated = encodeOpaquePng(image);
  assert.ok(encodeOpaquePng(image).equals(generated), "repeat builds are deterministic");
  const decoded = decodePng(generated);
  assert.equal(decoded.channels, 3);
  assert.deepEqual([...decoded.pixels], [255, 255, 255, 10, 20, 30, 127, 177, 227]);
});

test("contract preparation rejects damaged PNG data instead of producing altered content", () => {
  const source = fs.readFileSync(path.join(ROOT, "agreement-en.png"));
  assert.throws(() => decodePng(Buffer.from("not a png")), /signature/);
  assert.throws(() => decodePng(source.subarray(0, -1)), /Truncated/);
  const damaged = Buffer.from(source);
  damaged[100] ^= 1;
  assert.throws(() => decodePng(damaged), /checksum/);
});
