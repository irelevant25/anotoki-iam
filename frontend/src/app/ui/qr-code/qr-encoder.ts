/**
 * A QR encoder, written to ISO/IEC 18004 rather than pulled in as a package.
 *
 * The site carries no third-party runtime dependencies, and one library for one
 * picture on one screen was not worth being the first. What is here is the part
 * of the standard this site actually uses and no more:
 *
 *   byte mode      the input is a URI, and byte mode encodes any of it
 *   level M        the usual compromise - readable with a smudge on it,
 *                  without the size that level Q or H costs
 *   versions 1-10  up to 213 bytes, where an otpauth:// URI is about 140
 *
 * Anything longer throws rather than silently producing something unscannable.
 * Adding versions past 10 is a matter of extending the two tables below and the
 * alignment positions - the rest of the code does not know how big it is.
 *
 * Verified module for module against the qrcode-generator package across every
 * version in that range: same input, same matrix, every square.
 */

/** The error-correction level this encodes at, as the format field spells it. */
const EC_LEVEL_M_BITS = 0b00;

/** Byte mode, in the four bits that open the bit stream. */
const MODE_BYTE = 0b0100;

/** Alternated to fill whatever room is left after the data. */
const PAD_BYTES = [0xec, 0x11];

/**
 * Per version: error-correction codewords per block, then the blocks.
 *
 * A version's blocks come in at most two groups, the second holding one data
 * codeword more than the first. Straight out of the standard's table 13-22 for
 * level M.
 */
interface VersionSpec {
  /** Error-correction codewords in each block. */
  ecPerBlock: number;
  /** [number of blocks, data codewords in each]. */
  groups: [number, number][];
}

const VERSIONS: (VersionSpec | null)[] = [
  null, // there is no version 0
  { ecPerBlock: 10, groups: [[1, 16]] },
  { ecPerBlock: 16, groups: [[1, 28]] },
  { ecPerBlock: 26, groups: [[1, 44]] },
  { ecPerBlock: 18, groups: [[2, 32]] },
  { ecPerBlock: 24, groups: [[2, 43]] },
  { ecPerBlock: 16, groups: [[4, 27]] },
  { ecPerBlock: 18, groups: [[4, 31]] },
  {
    ecPerBlock: 22,
    groups: [
      [2, 38],
      [2, 39],
    ],
  },
  {
    ecPerBlock: 22,
    groups: [
      [3, 36],
      [2, 37],
    ],
  },
  {
    ecPerBlock: 26,
    groups: [
      [4, 43],
      [1, 44],
    ],
  },
];

/** Row and column centres of the alignment patterns, per version. */
const ALIGNMENT: number[][] = [[], [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50]];

// ─────────────────────────────────────────────────────────────────────────────
// GF(256)
// ─────────────────────────────────────────────────────────────────────────────
//
// Reed-Solomon works over the field the standard specifies: byte values, with
// multiplication modulo the primitive polynomial x^8 + x^4 + x^3 + x^2 + 1.
// The two tables turn that multiplication into an addition of logarithms.

const EXP = new Uint8Array(512);
const LOG = new Uint8Array(256);

(() => {
  let value = 1;
  for (let i = 0; i < 255; i++) {
    EXP[i] = value;
    LOG[value] = i;
    value <<= 1;
    if (value & 0x100) {
      value ^= 0x11d;
    }
  }
  // Doubled, so a sum of two logarithms never needs reducing before lookup.
  for (let i = 255; i < 512; i++) {
    EXP[i] = EXP[i - 255];
  }
})();

function gfMultiply(a: number, b: number): number {
  return a === 0 || b === 0 ? 0 : EXP[LOG[a] + LOG[b]];
}

/** The generator polynomial for `degree` error-correction codewords. */
function generatorPolynomial(degree: number): number[] {
  let poly = [1];

  for (let i = 0; i < degree; i++) {
    // Multiply by (x - alpha^i), which in this field is (x + alpha^i).
    const next = new Array<number>(poly.length + 1).fill(0);
    for (let j = 0; j < poly.length; j++) {
      next[j] ^= poly[j];
      next[j + 1] ^= gfMultiply(poly[j], EXP[i]);
    }
    poly = next;
  }

  return poly;
}

/** The error-correction codewords for one block. */
function errorCorrection(data: number[], ecLength: number): number[] {
  const generator = generatorPolynomial(ecLength);
  const remainder = new Array<number>(ecLength).fill(0);

  for (const byte of data) {
    const factor = byte ^ remainder[0];
    remainder.shift();
    remainder.push(0);

    if (factor !== 0) {
      for (let i = 0; i < ecLength; i++) {
        remainder[i] ^= gfMultiply(generator[i + 1], factor);
      }
    }
  }

  return remainder;
}

// ─────────────────────────────────────────────────────────────────────────────
// The data
// ─────────────────────────────────────────────────────────────────────────────

/** Total data codewords a version holds, across all its blocks. */
function dataCapacity(version: number): number {
  return VERSIONS[version]!.groups.reduce((total, [count, length]) => total + count * length, 0);
}

/**
 * How many bits the character count takes.
 *
 * Byte mode uses eight up to version 9 and sixteen from ten, which is the one
 * place the encoding depends on how big the symbol turned out to be.
 */
function countBits(version: number): number {
  return version < 10 ? 8 : 16;
}

/** The smallest version the data fits in, at level M. */
function chooseVersion(byteLength: number): number {
  for (let version = 1; version < VERSIONS.length; version++) {
    const overhead = Math.ceil((4 + countBits(version)) / 8);
    if (byteLength + overhead <= dataCapacity(version)) {
      return version;
    }
  }

  throw new Error(`QR: ${byteLength} bytes is more than version 10 at level M can carry`);
}

/**
 * The full codeword stream: data and error correction, interleaved.
 *
 * Interleaving is what makes a burst of damage land across several blocks
 * instead of destroying one of them, so the standard writes the blocks
 * column-wise rather than one after another.
 */
function codewords(bytes: number[], version: number): number[] {
  const spec = VERSIONS[version]!;

  // ── The bit stream ─────────────────────────────────────────────────────────
  const bits: number[] = [];
  const pushBits = (value: number, length: number) => {
    for (let i = length - 1; i >= 0; i--) {
      bits.push((value >> i) & 1);
    }
  };

  pushBits(MODE_BYTE, 4);
  pushBits(bytes.length, countBits(version));
  bytes.forEach((byte) => pushBits(byte, 8));

  const capacityBits = dataCapacity(version) * 8;

  // Terminator: up to four zeroes, or fewer if there is not room.
  for (let i = 0; i < 4 && bits.length < capacityBits; i++) {
    bits.push(0);
  }

  while (bits.length % 8 !== 0) {
    bits.push(0);
  }

  const data: number[] = [];
  for (let i = 0; i < bits.length; i += 8) {
    data.push(bits.slice(i, i + 8).reduce((byte, bit) => (byte << 1) | bit, 0));
  }

  // Whatever room is left is filled with the two pad bytes, alternating.
  while (data.length < dataCapacity(version)) {
    data.push(PAD_BYTES[(data.length - bits.length / 8) % 2]);
  }

  // ── Blocks ─────────────────────────────────────────────────────────────────
  const dataBlocks: number[][] = [];
  const ecBlocks: number[][] = [];
  let offset = 0;

  for (const [count, length] of spec.groups) {
    for (let i = 0; i < count; i++) {
      const block = data.slice(offset, offset + length);
      offset += length;
      dataBlocks.push(block);
      ecBlocks.push(errorCorrection(block, spec.ecPerBlock));
    }
  }

  // ── Interleave ─────────────────────────────────────────────────────────────
  const result: number[] = [];
  const longestBlock = Math.max(...dataBlocks.map((block) => block.length));

  for (let i = 0; i < longestBlock; i++) {
    for (const block of dataBlocks) {
      if (i < block.length) {
        result.push(block[i]);
      }
    }
  }

  for (let i = 0; i < spec.ecPerBlock; i++) {
    for (const block of ecBlocks) {
      result.push(block[i]);
    }
  }

  return result;
}

// ─────────────────────────────────────────────────────────────────────────────
// The symbol
// ─────────────────────────────────────────────────────────────────────────────

/** A module that has not been decided yet. */
type Module = boolean | null;

function placeFunctionPatterns(matrix: Module[][], version: number): void {
  const size = matrix.length;

  // Finder patterns and their separators, one per corner but the fourth.
  const finder = (top: number, left: number) => {
    for (let row = -1; row <= 7; row++) {
      for (let column = -1; column <= 7; column++) {
        const r = top + row;
        const c = left + column;
        if (r < 0 || r >= size || c < 0 || c >= size) {
          continue;
        }
        const inRing = (row === 0 || row === 6) && column >= 0 && column <= 6;
        const inSide = (column === 0 || column === 6) && row >= 0 && row <= 6;
        const inCore = row >= 2 && row <= 4 && column >= 2 && column <= 4;
        matrix[r][c] = inRing || inSide || inCore;
      }
    }
  };

  finder(0, 0);
  finder(0, size - 7);
  finder(size - 7, 0);

  // Timing patterns: the alternating row and column that fix the grid.
  for (let i = 8; i < size - 8; i++) {
    matrix[6][i] = i % 2 === 0;
    matrix[i][6] = i % 2 === 0;
  }

  // Alignment patterns, everywhere two centres cross - except the three
  // corners, where the finder patterns already are.
  const centres = ALIGNMENT[version];
  for (const row of centres) {
    for (const column of centres) {
      const nearFinder = (row === 6 && column === 6) || (row === 6 && column === size - 7) || (row === size - 7 && column === 6);
      if (nearFinder) {
        continue;
      }

      for (let dr = -2; dr <= 2; dr++) {
        for (let dc = -2; dc <= 2; dc++) {
          matrix[row + dr][column + dc] = Math.max(Math.abs(dr), Math.abs(dc)) !== 1;
        }
      }
    }
  }

  // The one module that is always dark.
  matrix[size - 8][8] = true;

  // Reserve the format areas so data does not get written into them.
  for (let i = 0; i < 9; i++) {
    if (matrix[8][i] === null) {
      matrix[8][i] = false;
    }
    if (matrix[i][8] === null) {
      matrix[i][8] = false;
    }
  }
  for (let i = 0; i < 8; i++) {
    if (matrix[8][size - 1 - i] === null) {
      matrix[8][size - 1 - i] = false;
    }
    if (matrix[size - 1 - i][8] === null) {
      matrix[size - 1 - i][8] = false;
    }
  }
}

/** True where a module belongs to the symbol's structure rather than the data. */
function functionMap(version: number, size: number): boolean[][] {
  const map: Module[][] = Array.from({ length: size }, () => new Array<Module>(size).fill(null));
  placeFunctionPatterns(map, version);

  // Version information, from version 7, sits beside the two far finders.
  if (version >= 7) {
    for (let i = 0; i < 18; i++) {
      const row = Math.floor(i / 3);
      const column = size - 11 + (i % 3);
      map[row][column] = false;
      map[column][row] = false;
    }
  }

  return map.map((row) => row.map((module) => module !== null));
}

/**
 * Writes the codewords into the symbol.
 *
 * Two columns at a time, right to left, snaking up and then down - and column
 * six is skipped entirely, because the vertical timing pattern lives there.
 */
function placeData(matrix: Module[][], reserved: boolean[][], stream: number[]): void {
  const size = matrix.length;
  let bit = 0;
  let upward = true;

  for (let right = size - 1; right > 0; right -= 2) {
    if (right === 6) {
      right = 5;
    }

    for (let step = 0; step < size; step++) {
      const row = upward ? size - 1 - step : step;

      for (let column of [right, right - 1]) {
        if (reserved[row][column]) {
          continue;
        }

        // Past the end of the data, the remainder bits are all light.
        const value = bit < stream.length * 8 && ((stream[bit >> 3] >> (7 - (bit % 8))) & 1) === 1;
        matrix[row][column] = value;
        bit++;
      }
    }

    upward = !upward;
  }
}

function maskAt(mask: number, row: number, column: number): boolean {
  switch (mask) {
    case 0:
      return (row + column) % 2 === 0;
    case 1:
      return row % 2 === 0;
    case 2:
      return column % 3 === 0;
    case 3:
      return (row + column) % 3 === 0;
    case 4:
      return (Math.floor(row / 2) + Math.floor(column / 3)) % 2 === 0;
    case 5:
      return ((row * column) % 2) + ((row * column) % 3) === 0;
    case 6:
      return (((row * column) % 2) + ((row * column) % 3)) % 2 === 0;
    default:
      return (((row + column) % 2) + ((row * column) % 3)) % 2 === 0;
  }
}

/**
 * How bad a masked symbol looks to a scanner. Lower is better.
 *
 * The four rules are the standard's: long runs of one colour, solid blocks,
 * anything resembling a finder pattern, and an overall bias towards dark or
 * light. Every mask is scored and the best one wins.
 */
function penalty(matrix: boolean[][]): number {
  const size = matrix.length;
  let score = 0;

  // Rule 1: runs of five or more.
  const scoreLine = (line: boolean[]) => {
    let run = 1;
    for (let i = 1; i < line.length; i++) {
      if (line[i] === line[i - 1]) {
        run++;
      } else {
        if (run >= 5) {
          score += 3 + (run - 5);
        }
        run = 1;
      }
    }
    if (run >= 5) {
      score += 3 + (run - 5);
    }
  };

  for (let i = 0; i < size; i++) {
    scoreLine(matrix[i]);
    scoreLine(matrix.map((row) => row[i]));
  }

  // Rule 2: two-by-two blocks of one colour.
  for (let row = 0; row < size - 1; row++) {
    for (let column = 0; column < size - 1; column++) {
      const first = matrix[row][column];
      if (first === matrix[row][column + 1] && first === matrix[row + 1][column] && first === matrix[row + 1][column + 1]) {
        score += 3;
      }
    }
  }

  // Rule 3: the finder-like sequence, either way round, in rows and columns.
  const patterns = [
    [true, false, true, true, true, false, true, false, false, false, false],
    [false, false, false, false, true, false, true, true, true, false, true],
  ];

  const scanForPatterns = (line: boolean[]) => {
    for (let i = 0; i + 11 <= line.length; i++) {
      for (const pattern of patterns) {
        if (pattern.every((value, offset) => line[i + offset] === value)) {
          score += 40;
        }
      }
    }
  };

  for (let i = 0; i < size; i++) {
    scanForPatterns(matrix[i]);
    scanForPatterns(matrix.map((row) => row[i]));
  }

  // Rule 4: how far from half the modules are dark.
  const dark = matrix.reduce((total, row) => total + row.filter(Boolean).length, 0);
  const percent = (dark * 100) / (size * size);
  score += Math.floor(Math.abs(percent - 50) / 5) * 10;

  return score;
}

/** BCH remainder, shared by the format and version fields. */
function bch(value: number, generator: number, generatorBits: number): number {
  let remainder = value;
  const bitLength = (n: number) => (n === 0 ? 0 : 32 - Math.clz32(n));

  while (bitLength(remainder) >= generatorBits) {
    remainder ^= generator << (bitLength(remainder) - generatorBits);
  }

  return remainder;
}

function placeFormat(matrix: Module[][], mask: number): void {
  const size = matrix.length;
  const data = (EC_LEVEL_M_BITS << 3) | mask;
  // The mask at the end is the standard's, and stops an all-zero format field
  // from being a run of light modules.
  const bits = (((data << 10) | bch(data << 10, 0x537, 11)) ^ 0x5412) & 0x7fff;

  const at = (i: number) => ((bits >> i) & 1) === 1;

  // Two copies, so a symbol with one corner damaged is still readable. The
  // first runs down column eight and the second along row eight, and the low
  // bits of each go nearest the top-left finder.
  for (let i = 0; i < 15; i++) {
    const row = i < 6 ? i : i < 8 ? i + 1 : size - 15 + i;
    matrix[row][8] = at(i);
  }

  for (let i = 0; i < 15; i++) {
    const column = i < 8 ? size - 1 - i : i === 8 ? 7 : 14 - i;
    matrix[8][column] = at(i);
  }
}

function placeVersion(matrix: Module[][], version: number): void {
  if (version < 7) {
    return;
  }

  const size = matrix.length;
  const bits = (version << 12) | bch(version << 12, 0x1f25, 13);

  for (let i = 0; i < 18; i++) {
    const value = ((bits >> i) & 1) === 1;
    const row = Math.floor(i / 3);
    const column = size - 11 + (i % 3);
    matrix[row][column] = value;
    matrix[column][row] = value;
  }
}

/**
 * The finished symbol: true is a dark module.
 *
 * The text is encoded as UTF-8 first, which for the URIs this is used on is
 * plain ASCII - but a name with an accent in it should produce a QR that reads
 * back as that name rather than as mojibake.
 */
export function encodeQr(text: string): boolean[][] {
  const bytes = Array.from(new TextEncoder().encode(text));
  const version = chooseVersion(bytes.length);
  const size = 21 + (version - 1) * 4;

  const reserved = functionMap(version, size);
  const stream = codewords(bytes, version);

  // Every mask is tried on its own copy, and the least ugly one is kept.
  let best: boolean[][] | null = null;
  let bestPenalty = Infinity;

  for (let mask = 0; mask < 8; mask++) {
    const matrix: Module[][] = Array.from({ length: size }, () => new Array<Module>(size).fill(null));
    placeFunctionPatterns(matrix, version);
    placeVersion(matrix, version);
    placeData(matrix, reserved, stream);

    for (let row = 0; row < size; row++) {
      for (let column = 0; column < size; column++) {
        if (!reserved[row][column] && maskAt(mask, row, column)) {
          matrix[row][column] = !matrix[row][column];
        }
      }
    }

    placeFormat(matrix, mask);

    const candidate = matrix.map((row) => row.map((module) => module === true));
    const score = penalty(candidate);

    if (score < bestPenalty) {
      bestPenalty = score;
      best = candidate;
    }
  }

  return best!;
}
