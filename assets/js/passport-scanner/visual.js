(function (global) {
  const MONTHS = {
    JAN: 1, FEB: 2, MAR: 3, APR: 4, MAY: 5, JUN: 6,
    JUL: 7, AUG: 8, SEP: 9, OCT: 10, NOV: 11, DEC: 12,
    JANUARY: 1, FEBRUARY: 2, MARCH: 3, APRIL: 4, JUNE: 6,
    JULY: 7, AUGUST: 8, SEPTEMBER: 9, OCTOBER: 10, NOVEMBER: 11, DECEMBER: 12
  };

  const NOISE = /^(PASSPORT|REPUBLIC|KINGDOM|UNITED|STATES|OF|THE|GOVERNMENT|TYPE|CODE|AUTHORITY|NATIONALITY|NATIONALITE|SURNAME|GIVEN|NAMES?|NAME|SEX|GENDER|BIRTH|DATE|EXPIRY|EXPIRES|VALID|UNTIL|DOCUMENT|NUMBER|NO|NOM|PRENOM|PRENOMS|SEXE|PAYS|APELLIDOS|NOMBRES)$/i;

  const DATE_TOKEN =
    '(?:' +
    '\\d{1,2}\\s+[A-Za-z]{3,9}\\s+\\d{2,4}|' +
    '\\d{1,2}[./-]\\d{1,2}[./-]\\d{2,4}|' +
    '\\d{4}[./-]\\d{1,2}[./-]\\d{1,2}|' +
    '\\d{1,2}[A-Za-z]{3}\\d{2,4}' +
    ')';

  function escapeRe(value) {
    return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  function fold(text) {
    return String(text || '')
      .replace(/\u2019/g, "'")
      .replace(/[|]/g, 'I')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '');
  }

  function cleanLines(text) {
    return fold(text)
      .split(/\r?\n/)
      .map((line) => line.replace(/\s+/g, ' ').trim())
      .filter(Boolean);
  }

  function pad(n) {
    return String(n).padStart(2, '0');
  }

  function toISO(day, month, year) {
    const d = Number(day);
    const m = Number(month);
    let y = Number(year);
    if (!d || !m || !y || d > 31 || m > 12) return '';
    if (y < 100) {
      const now = new Date().getFullYear() % 100;
      y = y > now + 10 ? 1900 + y : 2000 + y;
    }
    if (y < 1900 || y > 2100) return '';
    return `${y}-${pad(m)}-${pad(d)}`;
  }

  function parseDateToken(raw) {
    if (!raw) return '';
    const value = String(raw).trim().toUpperCase().replace(/,/g, ' ').replace(/\s+/g, ' ');

    let match = value.match(/^(\d{1,2})\s+([A-Z]{3,9})\s+(\d{2,4})$/);
    if (match && MONTHS[match[2]]) return toISO(match[1], MONTHS[match[2]], match[3]);

    match = value.match(/^(\d{1,2})[./-](\d{1,2})[./-](\d{2,4})$/);
    if (match) return toISO(match[1], match[2], match[3]);

    match = value.match(/^(\d{4})[./-](\d{1,2})[./-](\d{1,2})$/);
    if (match) return toISO(match[3], match[2], match[1]);

    match = value.match(/^(\d{1,2})([A-Z]{3})(\d{2,4})$/);
    if (match && MONTHS[match[2]]) return toISO(match[1], MONTHS[match[2]], match[3]);

    return '';
  }

  function stripTrailingLabelJunk(value) {
    return String(value || '')
      .replace(/^[:/\-\s]+/, '')
      .replace(/\s*\/\s*[A-Za-z][A-Za-z\s]*$/, '')
      .trim();
  }

  function looksLikeFieldValue(value) {
    const first = stripTrailingLabelJunk(value).split(/\s+/)[0] || '';
    return first.length > 1 && !NOISE.test(first);
  }

  function nextValueLine(lines, index) {
    for (let i = index + 1; i < Math.min(lines.length, index + 3); i++) {
      const line = stripTrailingLabelJunk(lines[i]);
      if (!line) continue;
      if (/^(?:surname|given|names?|nationality|nationalit|sex|gender|sexe|date|passport|document|type|code|issuing)\b/i.test(line)) {
        continue;
      }
      if (!looksLikeFieldValue(line)) continue;
      return line;
    }
    return '';
  }

  function lineHasLabel(line, labels) {
    const re = new RegExp('(?:^|\\b)(?:' + labels.map(escapeRe).join('|') + ')\\b', 'i');
    return re.test(line);
  }

  function findByLabels(lines, labels) {
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i];
      if (!lineHasLabel(line, labels)) continue;

      const sameLine = stripTrailingLabelJunk(
        line.replace(new RegExp('^(?:.*\\b)?(?:' + labels.map(escapeRe).join('|') + ')\\b\\s*[:/\\-]?', 'i'), '')
      );
      if (sameLine && looksLikeFieldValue(sameLine) && !lineHasLabel(sameLine, labels)) {
        return sameLine;
      }

      const next = nextValueLine(lines, i);
      if (next) return next;
    }
    return '';
  }

  function normalizePassportNumber(raw) {
    const token = String(raw || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    if (token.length < 6 || token.length > 12) return '';
    if (!/\d/.test(token)) return '';
    if (/^(PASSPORT|DOCUMENT|NUMBER)/.test(token)) return '';
    return token;
  }

  function findPassportNumber(text, lines) {
    for (let i = 0; i < lines.length; i++) {
      if (!/(?:passport\s*(?:no\.?|number|#?)|document\s*(?:no\.?|number)|passeport\s*n[o°]?|num[eé]ro\s+de\s+passeport)/i.test(lines[i])) {
        continue;
      }
      const same = normalizePassportNumber(lines[i].replace(/^.*?(?:passport\s*(?:no\.?|number|#?)|document\s*(?:no\.?|number)|passeport\s*n[o°]?|num[eé]ro\s+de\s+passeport)\s*[:/\-]?/i, ''));
      if (same) return same;
      const next = nextValueLine(lines, i);
      const fromNext = normalizePassportNumber(next);
      if (fromNext) return fromNext;
    }

    for (const line of lines) {
      const match = line.toUpperCase().match(/\b([A-Z]{1,3}\d{6,9}|\d{8,9}[A-Z]?)\b/);
      if (match) return match[1];
    }
    return '';
  }

  function findGender(text) {
    const match = text.match(
      /(?:sex|gender|sexe)(?:\s*\/\s*(?:sex|gender|sexe))?\s*[:/\-]?\s*(?:\r?\n\s*)?([MFX])\b/i
    );
    return match ? match[1].toUpperCase() : '';
  }

  function findCountryCode(lines, labels) {
    const labeled = findByLabels(lines, labels);
    if (!labeled) return '';
    const code = labeled.toUpperCase().match(/\b([A-Z]{3})\b/);
    if (code) return code[1];
    return labeled.replace(/[^A-Za-z ]/g, '').trim().slice(0, 40);
  }

  function findLabeledDate(text, labels) {
    const re = new RegExp(
      '(?:' + labels.map(escapeRe).join('|') + ')[\\s\\S]{0,80}?(' + DATE_TOKEN + ')',
      'i'
    );
    const match = text.match(re);
    return match ? parseDateToken(match[1]) : '';
  }

  function cleanPersonName(raw) {
    return stripTrailingLabelJunk(raw)
      .toUpperCase()
      .replace(/[^A-Z '\\-]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .split(' ')
      .filter((part) => part.length > 1 && !NOISE.test(part))
      .join(' ');
  }

  function parseNames(lines) {
    const last = cleanPersonName(findByLabels(lines, ['Surname', 'Last Name', 'Family Name', 'Nom', 'Apellidos']));
    const first = cleanPersonName(findByLabels(lines, ['Given Names', 'Given Name', 'First Name', 'Forenames', 'Prenoms', 'Prenom', 'Nombres']));

    if (last && !first && last.includes(' ')) {
      const parts = last.split(' ');
      return { last: parts[0], first: parts.slice(1).join(' ') };
    }

    return { last, first };
  }

  function emptyChecks() {
    return { passport: false, birth: false, expiry: false, personal: false, composite: false };
  }

  function parse(text) {
    const lines = cleanLines(text);
    const flat = lines.join('\n');

    const names = parseNames(lines);
    const passportNumber = findPassportNumber(flat, lines);
    const dateOfBirth = findLabeledDate(flat, [
      'Date of Birth',
      'Birth Date',
      'Birth',
      'DOB',
      'Date de naissance',
      'Fecha de nacimiento'
    ]);
    const expiryDate = findLabeledDate(flat, [
      'Date of Expiry',
      'Expiry Date',
      'Date of Expiration',
      'Expires',
      'Valid Until',
      'Date d expiration',
      "Date d'expiration",
      'Fecha de caducidad'
    ]);
    const gender = findGender(flat);
    let nationality = findCountryCode(lines, ['Nationality', 'Nationalite', 'Nacionalidad']);
    let issuingCountry = findCountryCode(lines, [
      'Issuing Country',
      'Issuing State',
      'Authority',
      'Country Code',
      'Pays'
    ]);

    if (nationality && nationality.length > 3) {
      const code = nationality.toUpperCase().match(/\b([A-Z]{3})\b/);
      nationality = code ? code[1] : nationality.slice(0, 3).toUpperCase();
    }
    if (issuingCountry && issuingCountry.length > 3) {
      const code = issuingCountry.toUpperCase().match(/\b([A-Z]{3})\b/);
      issuingCountry = code ? code[1] : '';
    }

    const filled = [names.first, names.last, passportNumber, nationality, dateOfBirth, gender, expiryDate, issuingCountry]
      .filter(Boolean).length;

    if (filled < 2) {
      throw new Error('Visual zone fields could not be read clearly. Try a sharper image with the passport data page fully visible.');
    }

    return {
      documentType: 'P',
      issuingCountry: issuingCountry || '',
      firstName: names.first || '',
      lastName: names.last || '',
      passportNumber: passportNumber || '',
      nationality: nationality || '',
      dateOfBirth: dateOfBirth || '',
      gender: gender || '',
      expiryDate: expiryDate || '',
      rawMrz: '',
      checks: emptyChecks(),
      valid: false,
      source: 'visual'
    };
  }

  global.Visual = { parse, parseDateToken };
})(window);
