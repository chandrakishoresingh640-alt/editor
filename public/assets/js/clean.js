/* Removes document info, XMP, and image metadata from PDFs this page creates. */
(function (root) {
  const META = new Set([
    "Metadata", "PieceInfo", "LastModified", "CreationDate", "ModDate",
    "Author", "Creator", "Producer", "Keywords", "Subject", "Trapped",
    "AF", "SpiderInfo", "Info",
  ]);

  function toBytes(input) {
    if (input instanceof Uint8Array) return input;
    return new Uint8Array(input);
  }

  function latin1(bytes) {
    let text = "";
    for (let i = 0; i < bytes.length; i += 4096) {
      const slice = bytes.subarray(i, Math.min(bytes.length, i + 4096));
      const chars = new Array(slice.length);
      for (let j = 0; j < slice.length; j++) chars[j] = slice[j];
      text += String.fromCharCode.apply(null, chars);
    }
    return text;
  }

  function bytesOf(text) {
    const out = new Uint8Array(text.length);
    for (let i = 0; i < text.length; i++) out[i] = text.charCodeAt(i) & 255;
    return out;
  }

  function concat(parts) {
    let length = 0;
    parts.forEach((part) => { length += part.length; });
    const out = new Uint8Array(length);
    let offset = 0;
    parts.forEach((part) => {
      out.set(part, offset);
      offset += part.length;
    });
    return out;
  }

  function skip(bytes, i, end) {
    while (i < end) {
      const c = bytes[i];
      if (c === 0 || c === 9 || c === 10 || c === 12 || c === 13 || c === 32) {
        i++;
        continue;
      }
      if (c === 37) {
        while (i < end && bytes[i] !== 10 && bytes[i] !== 13) i++;
        continue;
      }
      break;
    }
    return i;
  }

  function isDelim(c) {
    return c === 40 || c === 41 || c === 60 || c === 62 || c === 91 || c === 93 || c === 123 || c === 125 || c === 47 || c === 37;
  }

  function readWord(bytes, i, end) {
    i = skip(bytes, i, end);
    const start = i;
    while (i < end && bytes[i] > 32 && !isDelim(bytes[i])) i++;
    return { raw: bytes.subarray(start, i), next: i, type: "word" };
  }

  function decodeName(raw) {
    const text = latin1(raw).slice(1);
    return text.replace(/#([0-9A-Fa-f]{2})/g, (_, hex) => String.fromCharCode(parseInt(hex, 16)));
  }

  function parseName(bytes, i, end) {
    const start = i;
    i++;
    while (i < end && bytes[i] > 32 && !isDelim(bytes[i])) i++;
    const raw = bytes.subarray(start, i);
    return { raw, next: i, type: "name" };
  }

  function parseLiteral(bytes, i, end) {
    const start = i;
    i++;
    let depth = 1;
    while (i < end && depth > 0) {
      if (bytes[i] === 92) {
        i += 2;
        continue;
      }
      if (bytes[i] === 40) depth++;
      else if (bytes[i] === 41) depth--;
      i++;
    }
    return { raw: bytes.subarray(start, i), next: i, type: "literal" };
  }

  function parseHex(bytes, i, end) {
    const start = i;
    i++;
    while (i < end && bytes[i] !== 62) i++;
    return { raw: bytes.subarray(start, Math.min(end, i + 1)), next: Math.min(end, i + 1), type: "hex" };
  }

  function parseArray(bytes, i, end) {
    const start = i;
    i++;
    const parts = [bytesOf("[")];
    while (i < end) {
      i = skip(bytes, i, end);
      if (bytes[i] === 93) {
        parts.push(bytesOf("]"));
        i++;
        return { raw: bytes.subarray(start, i), cleaned: concat(parts), next: i, type: "array" };
      }
      const value = parseValue(bytes, i, end);
      parts.push(bytesOf(" "), value.cleaned || value.raw);
      i = value.next;
    }
    throw new Error("This PDF could not be rewritten without metadata.");
  }

  function parseDict(bytes, i, end) {
    const start = i;
    i += 2;
    const parts = [bytesOf("<<")];
    let streamLength = null;
    while (i < end) {
      i = skip(bytes, i, end);
      if (bytes[i] === 62 && bytes[i + 1] === 62) {
        parts.push(bytesOf(">>"));
        i += 2;
        return {
          raw: bytes.subarray(start, i),
          cleaned: concat(parts),
          next: i,
          type: "dict",
          streamLength,
        };
      }
      if (bytes[i] !== 47) throw new Error("This PDF could not be rewritten without metadata.");
      const name = parseName(bytes, i, end);
      const value = parseValue(bytes, name.next, end);
      const key = decodeName(name.raw);
      if (key === "Length" && value.type === "word" && /^-?\d+$/.test(latin1(value.raw))) {
        streamLength = parseInt(latin1(value.raw), 10);
      }
      if (!META.has(key)) {
        parts.push(bytesOf(" "), name.raw, bytesOf(" "), value.cleaned || value.raw);
      }
      i = value.next;
    }
    throw new Error("This PDF could not be rewritten without metadata.");
  }

  function parseValue(bytes, i, end) {
    i = skip(bytes, i, end);
    const c = bytes[i];
    if (c === 60 && bytes[i + 1] === 60) return parseDict(bytes, i, end);
    if (c === 60) return parseHex(bytes, i, end);
    if (c === 40) return parseLiteral(bytes, i, end);
    if (c === 91) return parseArray(bytes, i, end);
    if (c === 47) return parseName(bytes, i, end);
    const word = readWord(bytes, i, end);
    const text = latin1(word.raw);
    if (/^[+-]?(?:\d+\.?\d*|\.\d+)$/.test(text)) {
      const second = readWord(bytes, word.next, end);
      const secondText = latin1(second.raw);
      if (/^\d+$/.test(secondText)) {
        const third = readWord(bytes, second.next, end);
        if (latin1(third.raw) === "R") {
          return { raw: bytes.subarray(i, third.next), next: third.next, type: "ref" };
        }
      }
    }
    return { raw: bytes.subarray(i, word.next), next: word.next, type: "word" };
  }

  function keepJpegMarker(marker) {
    if (marker === 0xE0 || marker === 0xEE) return true;
    if (marker >= 0xE0 && marker <= 0xEF) return false;
    return marker !== 0xFE;
  }

  function jpeg(bytes) {
    if (bytes.length < 4 || bytes[0] !== 0xFF || bytes[1] !== 0xD8) return bytes;
    const parts = [bytes.subarray(0, 2)];
    let i = 2;
    while (i < bytes.length) {
      if (bytes[i] !== 0xFF) return bytes;
      while (i < bytes.length && bytes[i] === 0xFF) i++;
      if (i >= bytes.length) break;
      const marker = bytes[i++];
      if (marker === 0xD9) {
        parts.push(Uint8Array.of(0xFF, 0xD9));
        break;
      }
      if (marker === 0x01 || (marker >= 0xD0 && marker <= 0xD7)) {
        parts.push(Uint8Array.of(0xFF, marker));
        continue;
      }
      if (i + 1 >= bytes.length) return bytes;
      const seglen = (bytes[i] << 8) + bytes[i + 1];
      if (seglen < 2 || i + seglen > bytes.length) return bytes;
      const segment = bytes.subarray(i, i + seglen);
      i += seglen;
      if (marker === 0xDA) {
        parts.push(Uint8Array.of(0xFF, 0xDA), segment);
        const start = i;
        while (i < bytes.length) {
          if (bytes[i] !== 0xFF) {
            i++;
            continue;
          }
          const next = bytes[i + 1] || 0;
          if (next === 0x00 || (next >= 0xD0 && next <= 0xD7)) {
            i += 2;
            continue;
          }
          if (next === 0xFF) {
            i++;
            continue;
          }
          break;
        }
        parts.push(bytes.subarray(start, i));
        continue;
      }
      if (keepJpegMarker(marker)) parts.push(Uint8Array.of(0xFF, marker), segment);
    }
    const out = concat(parts);
    return out.length >= 2 && out[0] === 0xFF && out[1] === 0xD8 ? out : bytes;
  }

  function image(input) {
    const bytes = toBytes(input);
    if (bytes.length >= 2 && bytes[0] === 0xFF && bytes[1] === 0xD8) return jpeg(bytes);
    return bytes;
  }

  function setLength(dictBytes, length) {
    const text = latin1(dictBytes);
    if (/\/Length\s+\d+\s+\d+\s+R/.test(text)) return dictBytes;
    if (/\/Length\s+\d+/.test(text)) return bytesOf(text.replace(/\/Length\s+\d+/, "/Length " + length));
    return bytesOf(text.replace(/>>\s*$/, " /Length " + length + " >>"));
  }

  function forceDct(dictText) {
    let text = dictText.replace(/\/Filter\s*\[([^\]]*)\]/, (_, inner) => {
      const parts = inner.split(/\s+/).filter((part) => part && part !== "/FlateDecode" && part !== "/Fl");
      if (!parts.includes("/DCTDecode") && !parts.includes("/DCT")) parts.push("/DCTDecode");
      return parts.length === 1 ? "/Filter " + parts[0] : "/Filter [ " + parts.join(" ") + " ]";
    });
    text = text.replace(/\/Filter\s*\/FlateDecode\b/, "/Filter /DCTDecode");
    text = text.replace(/\/DecodeParms\s+<<(?:[^<>]|<<[^<>]*>>)*>>/, "");
    return text;
  }

  async function inflateZlib(data) {
    if (typeof DecompressionStream === "undefined") return null;
    const stream = new Blob([data]).stream().pipeThrough(new DecompressionStream("deflate"));
    return new Uint8Array(await new Response(stream).arrayBuffer());
  }

  async function cleanStream(dictText, data) {
    if (data.length >= 2 && data[0] === 0xFF && data[1] === 0xD8) {
      return { data: jpeg(data), dictText };
    }
    const hasDct = /\/DCTDecode\b|\/DCT\b/.test(dictText);
    const hasFlate = /\/FlateDecode\b|\/Fl\b/.test(dictText);
    if (hasDct && hasFlate) {
      try {
        const inflated = await inflateZlib(data);
        if (inflated && inflated.length >= 2 && inflated[0] === 0xFF && inflated[1] === 0xD8) {
          return { data: jpeg(inflated), dictText: forceDct(dictText) };
        }
      } catch (error) { /* leave the original stream in place */ }
    }
    return { data, dictText };
  }

  function parseXref(bytes) {
    const marker = latin1(bytes).lastIndexOf("startxref");
    if (marker < 0) throw new Error("This PDF could not be rewritten without metadata.");
    const match = latin1(bytes.subarray(marker, Math.min(bytes.length, marker + 40))).match(/startxref\s+(\d+)/);
    if (!match) throw new Error("This PDF could not be rewritten without metadata.");
    let i = skip(bytes, parseInt(match[1], 10), bytes.length);
    if (latin1(bytes.subarray(i, i + 4)) !== "xref") {
      throw new Error("This PDF could not be rewritten without metadata.");
    }
    i += 4;
    const entries = new Map();
    while (i < bytes.length) {
      i = skip(bytes, i, bytes.length);
      if (latin1(bytes.subarray(i, i + 7)) === "trailer") break;
      const startWord = readWord(bytes, i, bytes.length);
      const countWord = readWord(bytes, startWord.next, bytes.length);
      const start = parseInt(latin1(startWord.raw), 10);
      const count = parseInt(latin1(countWord.raw), 10);
      i = countWord.next;
      if (bytes[i] === 13) i++;
      if (bytes[i] === 10) i++;
      for (let n = 0; n < count; n++) {
        const row = latin1(bytes.subarray(i, i + 20));
        entries.set(start + n, { offset: parseInt(row.slice(0, 10), 10), gen: parseInt(row.slice(11, 16), 10), used: row[17] === "n" });
        i += 20;
      }
    }
    i = skip(bytes, i, bytes.length);
    if (latin1(bytes.subarray(i, i + 7)) !== "trailer") throw new Error("This PDF could not be rewritten without metadata.");
    i += 7;
    const trailer = parseValue(bytes, i, bytes.length);
    return { entries, trailer };
  }

  function parseIndirect(bytes, offset) {
    let i = skip(bytes, offset, bytes.length);
    const num = readWord(bytes, i, bytes.length);
    const gen = readWord(bytes, num.next, bytes.length);
    const word = readWord(bytes, gen.next, bytes.length);
    if (latin1(word.raw) !== "obj") throw new Error("This PDF could not be rewritten without metadata.");
    const value = parseValue(bytes, word.next, bytes.length);
    i = skip(bytes, value.next, bytes.length);
    let stream = null;
    if (latin1(bytes.subarray(i, i + 6)) === "stream") {
      i += 6;
      if (bytes[i] === 13) i++;
      if (bytes[i] === 10) i++;
      const length = value.streamLength;
      if (typeof length !== "number" || i + length > bytes.length) {
        throw new Error("This PDF could not be rewritten without metadata.");
      }
      stream = bytes.subarray(i, i + length);
    }
    return { num: parseInt(latin1(num.raw), 10), gen: parseInt(latin1(gen.raw), 10), value, stream };
  }

  function reachable(objects, rootNum) {
    const keep = new Set();
    const stack = [rootNum];
    while (stack.length) {
      const num = stack.pop();
      if (!num || keep.has(num) || !objects.has(num)) continue;
      keep.add(num);
      const object = objects.get(num);
      const body = object.dictBytes || object.value.cleaned || object.value.raw;
      const text = latin1(body);
      const re = /(\d+)\s+\d+\s+R/g;
      let found;
      while ((found = re.exec(text))) stack.push(parseInt(found[1], 10));
    }
    return keep;
  }

  async function pdf(input) {
    const bytes = toBytes(input);
    if (latin1(bytes.subarray(0, 5)) !== "%PDF-") {
      throw new Error("This PDF could not be rewritten without metadata.");
    }
    const parsed = parseXref(bytes);
    const objects = new Map();
    for (const [num, entry] of parsed.entries) {
      if (!entry.used || num === 0) continue;
      const object = parseIndirect(bytes, entry.offset);
      let dictBytes = object.value.type === "dict" ? object.value.cleaned : (object.value.cleaned || object.value.raw);
      let stream = object.stream;
      if (stream && object.value.type === "dict") {
        const cleaned = await cleanStream(latin1(dictBytes), stream);
        stream = cleaned.data;
        dictBytes = setLength(bytesOf(cleaned.dictText), stream.length);
      }
      objects.set(num, { gen: object.gen, value: object.value, dictBytes, stream });
    }
    const rootMatch = latin1(parsed.trailer.raw).match(/\/Root\s+(\d+)\s+(\d+)\s+R/);
    if (!rootMatch) throw new Error("This PDF could not be rewritten without metadata.");
    const keep = reachable(objects, parseInt(rootMatch[1], 10));
    const header = bytesOf("%PDF-1.7\n%\xE2\xE3\xCF\xD3\n");
    const chunks = [header];
    const offsets = new Map();
    const numbers = [...objects.keys()].filter((num) => keep.has(num)).sort((a, b) => a - b);
    const max = numbers.length ? numbers[numbers.length - 1] : 0;
    numbers.forEach((num) => {
      const object = objects.get(num);
      const body = object.stream
        ? concat([object.dictBytes, bytesOf("\nstream\n"), object.stream, bytesOf("\nendstream")])
        : (object.dictBytes || object.value.raw);
      const piece = concat([bytesOf(num + " " + object.gen + " obj\n"), body, bytesOf("\nendobj\n")]);
      offsets.set(num, chunks.reduce((sum, chunk) => sum + chunk.length, 0));
      chunks.push(piece);
    });
    const size = max + 1;
    let xref = "xref\n0 " + size + "\n0000000000 65535 f \n";
    const xrefAt = chunks.reduce((sum, chunk) => sum + chunk.length, 0);
    for (let num = 1; num < size; num++) {
      xref += offsets.has(num)
        ? String(offsets.get(num)).padStart(10, "0") + " " + String(objects.get(num).gen).padStart(5, "0") + " n \n"
        : "0000000000 65535 f \n";
    }
    xref += "trailer\n<< /Size " + size + " /Root " + rootMatch[1] + " " + rootMatch[2] + " R >>\nstartxref\n" + xrefAt + "\n%%EOF\n";
    chunks.push(bytesOf(xref));
    return concat(chunks);
  }

  async function download(pdfDocument, filename) {
    const raw = await pdfDocument.save({ useObjectStreams: false });
    const clean = await pdf(raw);
    const link = document.createElement("a");
    link.href = URL.createObjectURL(new Blob([clean], { type: "application/pdf" }));
    link.download = filename;
    link.click();
    setTimeout(() => URL.revokeObjectURL(link.href), 4000);
  }

  root.QuireClean = { pdf, image, jpeg, download };
})(typeof globalThis !== "undefined" ? globalThis : window);
