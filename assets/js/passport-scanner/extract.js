/**
 * Local passport OCR extract helper (MRZ-first, visual-zone fallback).
 * Requires: Tesseract, MRZ, Visual globals from sibling scripts.
 */
(function (global) {
  'use strict';

  var ALPHA3 = {
    USA: 'US', GBR: 'GB', ARE: 'AE', PAK: 'PK', IND: 'IN',
    SAU: 'SA', CAN: 'CA', AUS: 'AU', DEU: 'DE', FRA: 'FR',
    ITA: 'IT', ESP: 'ES', NLD: 'NL', TUR: 'TR', EGY: 'EG',
    CHN: 'CN', JPN: 'JP', KOR: 'KR', SGP: 'SG', MYS: 'MY',
    IDN: 'ID', PHL: 'PH', THA: 'TH', VNM: 'VN', BGD: 'BD',
    LKA: 'LK', NPL: 'NP', IRN: 'IR', IRQ: 'IQ', JOR: 'JO',
    LBN: 'LB', SYR: 'SY', KWT: 'KW', QAT: 'QA', BHR: 'BH',
    OMN: 'OM', YEM: 'YE', MAR: 'MA', DZA: 'DZ', TUN: 'TN',
    ZAF: 'ZA', NGA: 'NG', KEN: 'KE', ETH: 'ET', RUS: 'RU',
    UKR: 'UA', POL: 'PL', SWE: 'SE', NOR: 'NO', DNK: 'DK',
    FIN: 'FI', IRL: 'IE', PRT: 'PT', GRC: 'GR', CHE: 'CH',
    AUT: 'AT', BEL: 'BE', NZL: 'NZ', MEX: 'MX', BRA: 'BR',
    ARG: 'AR', CHL: 'CL', COL: 'CO', PER: 'PE'
  };

  function toAlpha2(code) {
    var value = String(code || '').toUpperCase().replace(/</g, '').trim();
    if (!value) return '';
    if (value.length === 2) return value;
    if (value.length === 3) return ALPHA3[value] || '';
    return '';
  }

  function normalizePayload(raw, source) {
    var first = String(raw.firstName || raw.first_name || '').trim();
    var last = String(raw.lastName || raw.last_name || '').trim();
    var gender = String(raw.gender || '').toUpperCase();
    if (gender === 'MALE') gender = 'M';
    if (gender === 'FEMALE') gender = 'F';
    if (gender !== 'M' && gender !== 'F' && gender !== 'X') gender = '';

    return {
      document_type: 'P',
      first_name: first,
      last_name: last,
      passport_number: String(raw.passportNumber || raw.passport_number || '').replace(/\s+/g, '').toUpperCase(),
      nationality: toAlpha2(raw.nationality),
      issuing_country: toAlpha2(raw.issuingCountry || raw.issuing_country),
      date_of_birth: String(raw.dateOfBirth || raw.date_of_birth || ''),
      expiry_date: String(raw.expiryDate || raw.expiry_date || ''),
      gender: gender,
      source: source || 'mrz',
      valid: !!raw.valid,
      checks: raw.checks || {},
      warnings: source === 'visual'
        ? ['MRZ was not detected. Visual zone fields were filled — please review carefully.']
        : (!raw.valid ? ['Passport MRZ read. Please review fields with failed validation.'] : [])
    };
  }

  function tryParseMrz(text) {
    if (!global.MRZ || typeof global.MRZ.parse !== 'function') return null;
    try {
      return global.MRZ.parse(text);
    } catch (e) {
      return null;
    }
  }

  function recognize(imageSource, options, onProgress) {
    if (!global.Tesseract || typeof global.Tesseract.recognize !== 'function') {
      return Promise.reject(new Error('OCR library could not load. Check your connection and refresh.'));
    }
    var opts = Object.assign({}, options || {});
    if (typeof onProgress === 'function') {
      opts.logger = onProgress;
    }
    return global.Tesseract.recognize(imageSource, 'eng', opts);
  }

  /**
   * @param {string|HTMLImageElement|File|Blob} imageSource
   * @param {{ onProgress?: function }} [options]
   * @returns {Promise<{ status:boolean, data:object, message?:string, warnings?:string[] }>}
   */
  function extract(imageSource, options) {
    options = options || {};
    var onProgress = options.onProgress;

    return recognize(imageSource, {
      tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<',
      preserve_interword_spaces: '0'
    }, onProgress).then(function (mrzResult) {
      var data = tryParseMrz(mrzResult && mrzResult.data ? mrzResult.data.text : '');
      if (data) {
        var payload = normalizePayload(data, 'mrz');
        return {
          status: true,
          data: payload,
          warnings: payload.warnings,
          message: data.valid
            ? 'Passport MRZ read and all check digits passed.'
            : 'Passport MRZ read. Please review fields with failed validation.'
        };
      }

      return recognize(imageSource, {
        preserve_interword_spaces: '1'
      }, onProgress).then(function (visualResult) {
        var text = visualResult && visualResult.data ? visualResult.data.text : '';
        data = tryParseMrz(text);
        if (data) {
          payload = normalizePayload(data, 'mrz');
          return {
            status: true,
            data: payload,
            warnings: payload.warnings,
            message: data.valid
              ? 'Passport MRZ read and all check digits passed.'
              : 'Passport MRZ read. Please review fields with failed validation.'
          };
        }

        if (!global.Visual || typeof global.Visual.parse !== 'function') {
          return {
            status: false,
            message: 'A passport MRZ was not detected. Please try a clearer image or enter details manually.'
          };
        }

        try {
          data = global.Visual.parse(text);
        } catch (visualErr) {
          return {
            status: false,
            message: (visualErr && visualErr.message)
              ? visualErr.message
              : 'A passport MRZ was not detected. Please try a clearer image or enter details manually.'
          };
        }
        payload = normalizePayload(data, 'visual');
        return {
          status: true,
          data: payload,
          warnings: payload.warnings,
          message: 'MRZ was not detected. Visual zone fields were filled — please review carefully before use.'
        };
      });
    }).catch(function (err) {
      return {
        status: false,
        message: (err && err.message) ? err.message : 'The passport could not be read.'
      };
    });
  }

  /**
   * Read a File/Blob as a data URL for OCR.
   */
  function fileToDataUrl(file) {
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onload = function () { resolve(reader.result); };
      reader.onerror = function () { reject(new Error('Could not read the image file.')); };
      reader.readAsDataURL(file);
    });
  }

  /**
   * @param {File|Blob} file
   * @param {{ onProgress?: function }} [options]
   */
  function extractFromFile(file, options) {
    return fileToDataUrl(file).then(function (dataUrl) {
      return extract(dataUrl, options);
    });
  }

  global.PassportLocalScanner = {
    extract: extract,
    extractFromFile: extractFromFile,
    fileToDataUrl: fileToDataUrl
  };
})(window);
