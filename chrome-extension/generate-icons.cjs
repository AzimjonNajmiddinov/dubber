/**
 * Generates PNG icons for Chrome extension using only Node.js built-ins.
 * Creates 16x16, 48x48, 128x128 px icons.
 * Run: node generate-icons.js
 */
const fs = require('fs');
const path = require('path');

function createPNG(size) {
    // PNG signature
    const sig = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);

    function chunk(type, data) {
        const typeBytes = Buffer.from(type, 'ascii');
        const len = Buffer.alloc(4);
        len.writeUInt32BE(data.length);
        const crcData = Buffer.concat([typeBytes, data]);
        const crc = crc32(crcData);
        const crcBuf = Buffer.alloc(4);
        crcBuf.writeUInt32BE(crc >>> 0);
        return Buffer.concat([len, typeBytes, data, crcBuf]);
    }

    // IHDR
    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(size, 0);   // width
    ihdr.writeUInt32BE(size, 4);   // height
    ihdr[8]  = 8;  // bit depth
    ihdr[9]  = 2;  // color type: RGB
    ihdr[10] = 0;  // compression
    ihdr[11] = 0;  // filter
    ihdr[12] = 0;  // interlace

    // Draw pixels
    const pixels = [];
    const cx = size / 2, cy = size / 2;
    const r = size / 2 - 1;

    for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
            const dx = x - cx + 0.5, dy = y - cy + 0.5;
            const dist = Math.sqrt(dx * dx + dy * dy);
            const corner = size * 0.15; // rounded corner radius as fraction

            // Rounded rectangle test
            const rx = Math.abs(dx) - (size / 2 - 1 - corner);
            const ry = Math.abs(dy) - (size / 2 - 1 - corner);
            let inside;
            if (rx <= 0 && ry <= 0) {
                inside = true;
            } else if (rx > 0 && ry > 0) {
                inside = Math.sqrt(rx * rx + ry * ry) <= corner;
            } else {
                inside = (rx <= 0 || ry <= 0) && Math.max(rx, ry) <= corner;
            }

            if (inside) {
                // Blue background: #1a73e8
                pixels.push(0x1a, 0x73, 0xe8);
            } else {
                // Transparent → white background
                pixels.push(255, 255, 255);
            }
        }
    }

    // Draw "UZ" text using simple pixel font
    if (size >= 16) {
        drawText(pixels, size, 'UZ', 255, 255, 255);
    }

    // Build IDAT
    const rawRows = [];
    for (let y = 0; y < size; y++) {
        rawRows.push(0); // filter type None
        for (let x = 0; x < size; x++) {
            const i = (y * size + x) * 3;
            rawRows.push(pixels[i], pixels[i+1], pixels[i+2]);
        }
    }
    const raw = Buffer.from(rawRows);
    const compressed = deflateSync(raw);

    const ihdrChunk = chunk('IHDR', ihdr);
    const idatChunk = chunk('IDAT', compressed);
    const iendChunk = chunk('IEND', Buffer.alloc(0));

    return Buffer.concat([sig, ihdrChunk, idatChunk, iendChunk]);
}

function drawText(pixels, size, text, r, g, b) {
    // Minimal 5x7 pixel font for U and Z
    const font = {
        'U': [
            [1,0,0,0,1],
            [1,0,0,0,1],
            [1,0,0,0,1],
            [1,0,0,0,1],
            [1,0,0,0,1],
            [1,0,0,0,1],
            [0,1,1,1,0],
        ],
        'Z': [
            [1,1,1,1,1],
            [0,0,0,1,0],
            [0,0,1,0,0],
            [0,1,0,0,0],
            [1,0,0,0,0],
            [1,0,0,0,0],
            [1,1,1,1,1],
        ],
    };

    const scale = Math.max(1, Math.floor(size / 16));
    const charW = 5 * scale, charH = 7 * scale;
    const gap = scale;
    const totalW = charW * text.length + gap * (text.length - 1);
    const startX = Math.floor((size - totalW) / 2);
    const startY = Math.floor((size - charH) / 2);

    text.split('').forEach((ch, ci) => {
        const glyph = font[ch];
        if (!glyph) return;
        const ox = startX + ci * (charW + gap);
        for (let gy = 0; gy < 7; gy++) {
            for (let gx = 0; gx < 5; gx++) {
                if (!glyph[gy][gx]) continue;
                for (let sy = 0; sy < scale; sy++) {
                    for (let sx = 0; sx < scale; sx++) {
                        const px = ox + gx * scale + sx;
                        const py = startY + gy * scale + sy;
                        if (px < 0 || px >= size || py < 0 || py >= size) continue;
                        const idx = (py * size + px) * 3;
                        pixels[idx] = r; pixels[idx+1] = g; pixels[idx+2] = b;
                    }
                }
            }
        }
    });
}

// Minimal zlib deflate (uncompressed blocks, valid PNG)
function deflateSync(data) {
    const out = [];
    // zlib header: CM=8, CINFO=7, FCHECK to make header divisible by 31
    out.push(0x78, 0x01);

    const BLOCK = 65535;
    for (let i = 0; i < data.length; i += BLOCK) {
        const block = data.slice(i, i + BLOCK);
        const last = (i + BLOCK >= data.length) ? 1 : 0;
        out.push(last);
        const len = block.length;
        out.push(len & 0xff, (len >> 8) & 0xff, (~len) & 0xff, ((~len) >> 8) & 0xff);
        for (let j = 0; j < block.length; j++) out.push(block[j]);
    }

    // Adler-32 checksum
    let s1 = 1, s2 = 0;
    for (let i = 0; i < data.length; i++) {
        s1 = (s1 + data[i]) % 65521;
        s2 = (s2 + s1) % 65521;
    }
    const adler = (s2 << 16) | s1;
    out.push((adler >>> 24) & 0xff, (adler >>> 16) & 0xff, (adler >>> 8) & 0xff, adler & 0xff);

    return Buffer.from(out);
}

// CRC-32
const crcTable = (() => {
    const t = new Uint32Array(256);
    for (let n = 0; n < 256; n++) {
        let c = n;
        for (let k = 0; k < 8; k++) c = (c & 1) ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        t[n] = c;
    }
    return t;
})();

function crc32(buf) {
    let crc = 0xffffffff;
    for (let i = 0; i < buf.length; i++) crc = crcTable[(crc ^ buf[i]) & 0xff] ^ (crc >>> 8);
    return (crc ^ 0xffffffff) >>> 0;
}

const outDir = path.join(__dirname, 'icons');
fs.mkdirSync(outDir, { recursive: true });

for (const size of [16, 48, 128]) {
    const png = createPNG(size);
    const outPath = path.join(outDir, `icon${size}.png`);
    fs.writeFileSync(outPath, png);
    console.log(`Created: icons/icon${size}.png (${png.length} bytes)`);
}
console.log('Done!');
