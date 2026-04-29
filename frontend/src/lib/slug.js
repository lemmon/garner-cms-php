const ASCII_FALLBACKS = {
  æ: 'ae',
  đ: 'd',
  ð: 'd',
  ħ: 'h',
  ı: 'i',
  ł: 'l',
  ø: 'o',
  œ: 'oe',
  þ: 'th',
  ß: 'ss',
};

export function generateSlug(value) {
  return String(value)
    .toLocaleLowerCase()
    .normalize('NFKD')
    .replace(/\p{M}+/gu, '')
    .replace(/[^\p{ASCII}]/gu, (character) => ASCII_FALLBACKS[character] ?? '-')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}
