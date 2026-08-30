// ============================================================================
// CUSTOM TIME PICKER LIBRARY
// Matches the datepicker design: overlay, centered popup, Tailwind styling
// Usage: $('input').timepicker({ defaultHour: 10, defaultMinute: 0 })
// ============================================================================
!function($) {

  var Timepicker = function(element, options) {
    this.element = $(element);
    this.options = $.extend({}, $.fn.timepicker.defaults, options);
    this.hour = this.options.defaultHour;
    this.minute = this.options.defaultMinute;
    this.view = 'hours';
    this.isInput = this.element.is('input');

    if (this.isInput) {
      var val = this.element.val();
      if (val && /^\d{1,2}:\d{2}$/.test(val)) {
        var parts = val.split(':');
        this.hour = parseInt(parts[0], 10);
        this.minute = parseInt(parts[1], 10);
      } else {
        // Empty or invalid — write defaultHour:defaultMinute (10:00) into the field.
        this.set();
      }
    }

    this.picker = $(this.buildTemplate()).appendTo('body');
    this.picker.on('click', $.proxy(this.click, this));

    if (this.isInput) {
      this.element.on({ click: $.proxy(this.show, this), focus: $.proxy(this.show, this) });
      this.element.prop('readonly', true);
      this.element.css('cursor', 'pointer');
    } else {
      this.element.on('click', $.proxy(this.show, this));
    }
  };

  Timepicker.prototype = {
    constructor: Timepicker,

    buildTemplate: function() {
      return '<div class="timepicker hidden bg-white rounded-lg shadow-2xl border border-gray-200 p-4 transition-opacity duration-200 ease-out" style="min-width:280px; max-width: 320px;">' +
        '<div class="timepicker-header flex items-center justify-between mb-3 pb-3 border-b border-gray-200">' +
          '<div class="flex items-center gap-2">' +
            '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-gray-600"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' +
            '<span class="timepicker-display font-bold text-base text-gray-900"></span>' +
          '</div>' +
          '<div class="flex gap-1">' +
            '<button type="button" class="tp-tab tp-tab-hours px-3 py-1 text-xs font-semibold rounded-full transition-all duration-150">Hours</button>' +
            '<button type="button" class="tp-tab tp-tab-minutes px-3 py-1 text-xs font-semibold rounded-full transition-all duration-150">Minutes</button>' +
          '</div>' +
        '</div>' +
        '<div class="timepicker-hours"></div>' +
        '<div class="timepicker-minutes hidden"></div>' +
      '</div>';
    },

    show: function(e) {
      var that = this;
      if (this.isShowing) return;
      this.isShowing = true;

      $('.timepicker-overlay').remove();
      this.overlay = $('<div class="timepicker-overlay"></div>')
        .css({ position: 'fixed', top: 0, left: 0, right: 0, bottom: 0, backgroundColor: 'rgba(0, 0, 0, 0.4)', zIndex: 9998, opacity: 0 })
        .appendTo('body')
        .animate({ opacity: 1 }, 200)
        .on('click', function(ev) { ev.preventDefault(); ev.stopPropagation(); that.hide(); });

      this.view = 'hours';
      this.render();
      this.picker.removeClass('hidden').css('opacity', 0);
      setTimeout(function() { that.picker.css('opacity', 1); }, 10);

      this.place();
      if (window.requestAnimationFrame) {
        requestAnimationFrame(function() { that.place(); });
      }
      setTimeout(function() { that.place(); }, 50);

      this._onResize = $.proxy(this.place, this);
      $(window).on('resize.timepicker', this._onResize);
      if (e) { e.stopPropagation(); e.preventDefault(); }
      
      this._onMousedown = function(ev) {
        if ($(ev.target).closest('.timepicker').length === 0 && !$(ev.target).hasClass('timepicker-overlay') && !$(ev.target).is(that.element)) {
          that.hide();
        }
      };
      $(document).on('mousedown.timepicker', this._onMousedown);
      this.element.trigger({ type: 'show', hour: this.hour, minute: this.minute });
    },

    hide: function() {
      var that = this;
      this.isShowing = false;
      this.picker.css('opacity', 0);
      if (this.overlay) {
        var ov = this.overlay;
        this.overlay = null;
        ov.animate({ opacity: 0 }, 200, function() { ov.remove(); });
      }
      setTimeout(function() { that.picker.addClass('hidden'); }, 200);
      if (this._onResize) { $(window).off('resize.timepicker', this._onResize); this._onResize = null; }
      if (this._onMousedown) { $(document).off('mousedown.timepicker', this._onMousedown); this._onMousedown = null; }
      this.element.trigger({ type: 'hide', hour: this.hour, minute: this.minute });
    },

    place: function() {
      var isMobile = window.innerWidth <= 768;
      var scrollLeft = $(window).scrollLeft();
      var scrollTop = $(window).scrollTop();
      if (isMobile) {
        this.picker.css({
          position: 'fixed',
          top: '50%',
          left: '50%',
          transform: 'translate(-50%, -50%)',
          width: '90vw',
          maxWidth: '320px',
          zIndex: 9999
        });
        return;
      }

      var $anchor = this.element;
      var $fieldSegment = this.element.closest('.field-box-segment');
      var $fieldBox = this.element.closest('.field-box');
      if ($fieldSegment.length) {
        $anchor = $fieldSegment;
      } else if ($fieldBox.length) {
        $anchor = $fieldBox;
      }

      var offset = $anchor.offset();
      if (!offset || (offset.top === 0 && offset.left === 0)) {
        var elOffset = this.element.offset();
        if (elOffset && (elOffset.top > 0 || elOffset.left > 0)) {
          offset = elOffset;
          $anchor = this.element;
        } else {
          return;
        }
      }

      this.picker.css({ position: 'absolute', transform: 'none', width: 'auto', maxWidth: '320px', zIndex: 9999 });
      var pickerWidth = this.picker.outerWidth() || 300;
      var pickerHeight = this.picker.outerHeight() || 250;
      var viewportWidth = window.innerWidth;
      var viewportHeight = window.innerHeight;
      var anchorWidth = $anchor.outerWidth();
      var anchorHeight = $anchor.outerHeight();

      var spaceBelow = scrollTop + viewportHeight - (offset.top + anchorHeight);
      var spaceAbove = offset.top - scrollTop;

      var left = Math.min(Math.max(scrollLeft + 10, offset.left), scrollLeft + viewportWidth - pickerWidth - 10);
      var top;
      if (spaceBelow >= pickerHeight + 10 || spaceBelow >= spaceAbove) {
        top = offset.top + anchorHeight + 8;
      } else {
        top = offset.top - pickerHeight - 8;
      }

      this.picker.css({ top: top, left: left });
    },

    set: function() {
      var formatted = (this.hour < 10 ? '0' : '') + this.hour + ':' + (this.minute < 10 ? '0' : '') + this.minute;
      if (this.isInput) { this.element.val(formatted); } else { this.element.data('time', formatted); }
    },

    render: function() {
      var display = (this.hour < 10 ? '0' : '') + this.hour + ':' + (this.minute < 10 ? '0' : '') + this.minute;
      this.picker.find('.timepicker-display').text(display);
      this.picker.find('.tp-tab-hours').toggleClass('bg-blue-600 text-white', this.view === 'hours').toggleClass('bg-gray-100 text-gray-600 hover:bg-gray-200', this.view !== 'hours');
      this.picker.find('.tp-tab-minutes').toggleClass('bg-blue-600 text-white', this.view === 'minutes').toggleClass('bg-gray-100 text-gray-600 hover:bg-gray-200', this.view !== 'minutes');
      if (this.view === 'hours') {
        this.renderHours();
        this.picker.find('.timepicker-hours').removeClass('hidden');
        this.picker.find('.timepicker-minutes').addClass('hidden');
      } else {
        this.renderMinutes();
        this.picker.find('.timepicker-minutes').removeClass('hidden');
        this.picker.find('.timepicker-hours').addClass('hidden');
      }
    },

    renderHours: function() {
      var html = '<div class="grid grid-cols-6 gap-1">';
      for (var h = 0; h < 24; h++) {
        var label = (h < 10 ? '0' : '') + h;
        var cls = (h === this.hour) ? 'bg-blue-600 text-white font-bold shadow-md' : 'bg-gray-50 text-gray-800 hover:bg-blue-50 hover:text-blue-700 border-2 border-transparent hover:border-blue-200';
        html += '<div class="tp-hour flex items-center justify-center w-9 h-9 rounded-full cursor-pointer text-sm font-semibold transition-all duration-150 ' + cls + '" data-hour="' + h + '">' + label + '</div>';
      }
      html += '</div>';
      this.picker.find('.timepicker-hours').html(html);
    },

    renderMinutes: function() {
      var html = '<div class="grid grid-cols-6 gap-1 mb-2">';
      for (var m = 0; m < 60; m += 5) {
        var label = (m < 10 ? '0' : '') + m;
        var cls = (m === this.minute) ? 'bg-blue-600 text-white font-bold shadow-md' : 'bg-gray-50 text-gray-800 hover:bg-blue-50 hover:text-blue-700 border-2 border-transparent hover:border-blue-200';
        html += '<div class="tp-minute flex items-center justify-center w-9 h-9 rounded-full cursor-pointer text-sm font-semibold transition-all duration-150 ' + cls + '" data-minute="' + m + '">' + label + '</div>';
      }
      html += '</div>';
      html += '<div class="border-t border-gray-200 pt-2 mt-1">';
      html += '<div class="text-xs text-gray-500 mb-1.5 px-1 font-medium">Fine tune</div>';
      html += '<div class="grid grid-cols-10 gap-[2px]">';
      for (var m = 0; m < 60; m++) {
        if (m % 5 === 0) continue;
        var label = (m < 10 ? '0' : '') + m;
        var cls = (m === this.minute) ? 'bg-blue-600 text-white font-bold shadow-sm' : 'bg-gray-50 text-gray-700 hover:bg-blue-50 hover:text-blue-700';
        html += '<div class="tp-minute flex items-center justify-center w-7 h-7 rounded-full cursor-pointer text-[11px] font-semibold transition-all duration-100 ' + cls + '" data-minute="' + m + '">' + label + '</div>';
      }
      html += '</div></div>';
      this.picker.find('.timepicker-minutes').html(html);
    },

    click: function(e) {
      e.stopPropagation(); e.preventDefault();
      var target = $(e.target);
      if (target.hasClass('tp-tab-hours') || target.closest('.tp-tab-hours').length) { this.view = 'hours'; this.render(); return; }
      if (target.hasClass('tp-tab-minutes') || target.closest('.tp-tab-minutes').length) { this.view = 'minutes'; this.render(); return; }
      var hourEl = target.hasClass('tp-hour') ? target : target.closest('.tp-hour');
      if (hourEl.length) { this.hour = parseInt(hourEl.data('hour'), 10); this.set(); this.view = 'minutes'; this.render(); this.element.trigger({ type: 'changeTime', hour: this.hour, minute: this.minute }); return; }
      var minEl = target.hasClass('tp-minute') ? target : target.closest('.tp-minute');
      if (minEl.length) { this.minute = parseInt(minEl.data('minute'), 10); this.set(); this.render(); this.element.trigger({ type: 'changeTime', hour: this.hour, minute: this.minute }); this.hide(); return; }
    }
  };

  $.fn.timepicker = function(option, val) {
    return this.each(function() {
      var $this = $(this), data = $this.data('timepicker'), options = typeof option === 'object' && option;
      if (!data) { $this.data('timepicker', (data = new Timepicker(this, $.extend({}, $.fn.timepicker.defaults, options)))); }
      if (typeof option === 'string') data[option](val);
    });
  };

  $.fn.timepicker.defaults = { defaultHour: 10, defaultMinute: 0 };
  $.fn.timepicker.Constructor = Timepicker;

}(window.jQuery);

// ============================================================================
// DATEPICKER LIBRARY
// ============================================================================
!function($) {
  var Datepicker = function(element, options) {
    this.element = $(element);
    this.format = DPGlobal.parseFormat(options.format || this.element.data('date-format') || 'mm/dd/yyyy');
    this.picker = $(DPGlobal.template)
      .appendTo('body')
      .on({click: $.proxy(this.click, this)});
    // MOBILE CLOSE BUTTON
    this.picker.find('.datepicker-close').on('click', $.proxy(function(e) {
      e.preventDefault();
      e.stopPropagation();
      this.hide();
    }, this));
    this.isInput = this.element.is('input');
    this.component = this.element.is('.date') ? this.element.find('.add-on') : false;

    if (this.isInput) {
      this.element.on({
        focus: $.proxy(this.show, this),
        keyup: $.proxy(this.update, this)
      });
    } else {
      if (this.component) {
        this.component.on('click', $.proxy(this.show, this));
      } else {
        this.element.on('click', $.proxy(this.show, this));
      }
    }

    this.minViewMode = options.minViewMode || this.element.data('date-minviewmode') || 0;
    if (typeof this.minViewMode === 'string') {
      switch (this.minViewMode) {
        case 'months':
          this.minViewMode = 1;
          break;
        case 'years':
          this.minViewMode = 2;
          break;
        default:
          this.minViewMode = 0;
          break;
      }
    }

    this.viewMode = options.viewMode || this.element.data('date-viewmode') || 0;
    if (typeof this.viewMode === 'string') {
      switch (this.viewMode) {
        case 'months':
          this.viewMode = 1;
          break;
        case 'years':
          this.viewMode = 2;
          break;
        default:
          this.viewMode = 0;
          break;
      }
    }

    this.startViewMode = this.viewMode;
    this.weekStart = options.weekStart || this.element.data('date-weekstart') || 0;
    this.weekEnd = this.weekStart === 0 ? 6 : this.weekStart - 1;
    this.onRender = options.onRender;
    this.useDisplayFormat = options.useDisplayFormat === true;
    this.fillDow();
    this.fillMonths();
    this.update();
    if (this.useDisplayFormat) {
      var initialVal = this.isInput ? String(this.element.val() || '').trim() : '';
      if (initialVal) {
        this.set();
      }
    }
    this.showMode();
  };

  Datepicker.prototype = {
    constructor: Datepicker,

    show: function(e) {
      var that = this;

      // Always remove any existing overlay first
      $('.datepicker-overlay').remove();

      // Create new overlay — OWNED BY THIS PICKER. hide() only removes its own
      // overlay, so when one picker opens another (flights departure -> return)
      // the closing picker cannot destroy the new picker's backdrop.
      this.overlay = $('<div class="datepicker-overlay"></div>')
        .css({
          position: 'fixed',
          top: 0,
          left: 0,
          right: 0,
          bottom: 0,
          backgroundColor: 'rgba(0, 0, 0, 0.4)',
          zIndex: 9998,
          opacity: 0
        })
        .appendTo('body')
        .animate({opacity: 1}, 200)
        .on('click', function(ev) {
          ev.preventDefault();
          ev.stopPropagation();
          that.hide();
        });

      this.picker.removeClass('hidden').css('opacity', 0);

      setTimeout(function() {
        that.picker.css('opacity', 1);
      }, 10);

      this.height = this.component ? this.component.outerHeight() : this.element.outerHeight();
      this.place();
      if (window.requestAnimationFrame) {
        requestAnimationFrame(function() { that.place(); });
      }
      setTimeout(function() { that.place(); }, 50);

      // OWNED HANDLERS — hide() UNBINDS ONLY THIS PICKER'S, NOT EVERY PICKER'S
      this._onResize = $.proxy(this.place, this);
      $(window).on('resize.datepicker', this._onResize);

      if (e) {
        e.stopPropagation();
        e.preventDefault();
      }

      this._onMousedown = function(ev) {
        if ($(ev.target).closest('.datepicker').length == 0 && !$(ev.target).hasClass('datepicker-overlay')) {
          that.hide();
        }
      };
      $(document).on('mousedown.datepicker', this._onMousedown);

      this.element.trigger({type: 'show', date: this.date});
    },

    hide: function() {
      var that = this;

      this.picker.css('opacity', 0);

      // ONLY REMOVE THE OVERLAY THIS PICKER CREATED (SEE show())
      if (this.overlay) {
        var ov = this.overlay;
        this.overlay = null;
        ov.animate({opacity: 0}, 200, function() { ov.remove(); });
      }

      setTimeout(function() {
        that.picker.addClass('hidden');
      }, 200);

      if (this._onResize) { $(window).off('resize.datepicker', this._onResize); this._onResize = null; }
      if (this._onMousedown) { $(document).off('mousedown.datepicker', this._onMousedown); this._onMousedown = null; }

      this.viewMode = this.startViewMode;
      this.showMode();

      this.element.trigger({type: 'hide', date: this.date});
    },

    set: function() {
      if (this.useDisplayFormat) {
        var storage = DPGlobal.formatStorageDate(this.date);
        var display = DPGlobal.formatDisplayDate(this.date);
        if (!this.isInput) {
          if (this.component) {
            this.element.find('input').prop('value', display);
          }
          this.element.data('date', storage);
        } else {
          this.element.data('date-value', storage);
          this.element.prop('value', display);
        }
        return;
      }
      var formated = DPGlobal.formatDate(this.date, this.format);
      if (!this.isInput) {
        if (this.component) {
          this.element.find('input').prop('value', formated);
        }
        this.element.data('date', formated);
      } else {
        this.element.prop('value', formated);
      }
    },

    setValue: function(newDate) {
      if (typeof newDate === 'string') {
        this.date = DPGlobal.parseDate(newDate, this.format);
      } else {
        this.date = new Date(newDate);
      }
      this.set();
      this.viewDate = new Date(this.date.getFullYear(), this.date.getMonth(), 1, 0, 0, 0, 0);
      this.fill();
    },

    place: function() {
      var offset = this.component ? this.component.offset() : this.element.offset();
      var isMobile = window.innerWidth <= 768;
      var scrollLeft = $(window).scrollLeft();
      var scrollTop = $(window).scrollTop();

      if (isMobile) {
        // FULL-SCREEN SHEET ON MOBILE (MATCHES THE SEARCH DROPDOWNS)
        this.picker.css({
          position: 'fixed',
          top: 0,
          left: 0,
          right: 0,
          bottom: 0,
          transform: 'none',
          width: '100vw',
          height: '100dvh',
          maxWidth: 'none',
          borderRadius: 0,
          zIndex: 99999,
          overflowY: 'auto'
        });
        this.picker.find('.datepicker-mobile-header').css('display', 'flex');
        return;
      }

      // DESKTOP — DROPDOWN UNDER THE FIELD BOX, SAME WIDTH AS THE FIELD (RESET
      // ANY MOBILE FULL-SCREEN STYLES). ANCHORED TO .field-box WHEN PRESENT SO
      // IT ALIGNS WITH THE VISIBLE INPUT BOX, NOT THE INNER TEXT INPUT.
      this.picker.find('.datepicker-mobile-header').css('display', 'none');
      var $anchor = this.component && this.component.length ? this.component : this.element;
      var $fieldSegment = this.element.closest('.field-box-segment');
      var $fieldBox = this.element.closest('.field-box');
      if ((!this.component || !this.component.length) && $fieldSegment.length) {
        $anchor = $fieldSegment;
      } else if ((!this.component || !this.component.length) && $fieldBox.length) {
        $anchor = $fieldBox;
      }
      var anchorOffset = $anchor.offset();
      if (!anchorOffset || (anchorOffset.top === 0 && anchorOffset.left === 0)) {
        var elOffset = this.element.offset();
        if (elOffset && (elOffset.top > 0 || elOffset.left > 0)) {
          anchorOffset = elOffset;
          $anchor = this.element;
        } else {
          return;
        }
      }
      var anchorWidth = $anchor.outerWidth();
      var anchorHeight = $anchor.outerHeight();
      this.picker.css({ position: 'absolute', transform: 'none', height: 'auto', right: 'auto', bottom: 'auto', borderRadius: '', overflowY: 'visible', zIndex: 9999, width: Math.max(anchorWidth, 320), maxWidth: 'none' });
      var pickerWidth = this.picker.outerWidth() || 320;
      var viewportWidth = window.innerWidth;
      // ALWAYS BELOW THE FIELD, LEFT-ALIGNED WITH IT, CLAMPED TO THE VIEWPORT
      var left = Math.min(Math.max(scrollLeft + 10, anchorOffset.left), scrollLeft + viewportWidth - pickerWidth - 10);
      var top = anchorOffset.top + anchorHeight + 8;
      this.picker.css({ top: top, left: left });
    },

    update: function(newDate) {
      var raw = typeof newDate === 'string' ? newDate : (this.isInput ? this.element.prop('value') : this.element.data('date'));
      if (this.useDisplayFormat) {
        var stored = this.isInput ? this.element.data('date-value') : null;
        if (typeof newDate !== 'string' && stored) {
          this.date = DPGlobal.parseDate(stored, this.format);
        } else {
          var parsed = DPGlobal.parseFlexibleDate(raw, this.format);
          this.date = parsed || new Date();
        }
      } else {
        this.date = DPGlobal.parseDate(raw, this.format);
      }
      this.viewDate = new Date(this.date.getFullYear(), this.date.getMonth(), 1, 0, 0, 0, 0);
      this.fill();
    },

    fillDow: function() {
      var dowCnt = this.weekStart;
      var html = '<tr>';
      while (dowCnt < this.weekStart + 7) {
        html += '<th class="text-center text-xs font-semibold text-gray-600 uppercase py-2">' + DPGlobal.dates.daysMin[(dowCnt++) % 7] + '</th>';
      }
      html += '</tr>';
      this.picker.find('.datepicker-days thead').append(html);
    },

    fillMonths: function() {
      var html = '';
      var i = 0;
      while (i < 12) {
        html += '<span class="month inline-flex items-center justify-center w-[calc(25%-8px)] h-9 m-1 cursor-pointer hover:bg-blue-50 rounded-full transition-all duration-150 font-semibold text-sm text-gray-800 border-2 border-transparent hover:border-blue-200 flex-shrink-0">' + DPGlobal.dates.monthsShort[i++] + '</span>';
      }
      this.picker.find('.datepicker-months td').append(html);
    },

    fill: function() {
      var d = new Date(this.viewDate),
          year = d.getFullYear(),
          month = d.getMonth(),
          currentDate = this.date.valueOf();

      this.picker.find('.datepicker-days th:eq(1)').text(DPGlobal.dates.months[month] + ' ' + year);

      var prevMonth = new Date(year, month - 1, 28, 0, 0, 0, 0),
          day = DPGlobal.getDaysInMonth(prevMonth.getFullYear(), prevMonth.getMonth());

      prevMonth.setDate(day);
      prevMonth.setDate(day - (prevMonth.getDay() - this.weekStart + 7) % 7);

      var nextMonth = new Date(prevMonth);
      nextMonth.setDate(nextMonth.getDate() + 42);
      nextMonth = nextMonth.valueOf();

      var html = [];
      var clsName, prevY, prevM;

      while (prevMonth.valueOf() < nextMonth) {
        if (prevMonth.getDay() === this.weekStart) {
          html.push('<tr>');
        }

        clsName = this.onRender(prevMonth);
        prevY = prevMonth.getFullYear();
        prevM = prevMonth.getMonth();

        var dayClasses = 'day cursor-pointer rounded-full transition-all duration-150 font-semibold text-sm aspect-square flex-shrink-0';

        if ((prevM < month && prevY === year) || prevY < year) {
          dayClasses += ' old text-gray-400 hover:text-gray-500 hover:bg-gray-50';
        } else if ((prevM > month && prevY === year) || prevY > year) {
          dayClasses += ' new text-gray-400 hover:text-gray-500 hover:bg-gray-50';
        } else {
          dayClasses += ' text-gray-900 hover:bg-blue-50';
        }

        if (prevMonth.valueOf() === currentDate) {
          dayClasses += ' active !bg-blue-600 !text-white hover:!bg-blue-700 shadow-md';
        }

        if (clsName.indexOf('disabled') !== -1) {
          dayClasses += ' disabled cursor-not-allowed opacity-40 hover:bg-transparent';
        }

        html.push('<td class="p-0.2 text-center"><div class="' + dayClasses + ' ' + clsName + ' w-10 h-10 flex items-center justify-center mx-auto">' + prevMonth.getDate() + '</div></td>');

        if (prevMonth.getDay() === this.weekEnd) {
          html.push('</tr>');
        }

        prevMonth.setDate(prevMonth.getDate() + 1);
      }

      this.picker.find('.datepicker-days tbody').empty().append(html.join(''));

      var currentYear = this.date.getFullYear();
      var months = this.picker.find('.datepicker-months')
        .find('th:eq(1)')
        .text(year)
        .end()
        .find('span')
        .removeClass('active !bg-blue-600 !text-white shadow-md !border-blue-600')
        .addClass('text-gray-800');

      if (currentYear === year) {
        months.eq(this.date.getMonth()).removeClass('text-gray-800').addClass('active !bg-blue-600 !text-white hover:!bg-blue-700 shadow-md !border-blue-600');
      }

      html = '';
      year = parseInt(year / 10, 10) * 10;
      var yearCont = this.picker.find('.datepicker-years')
        .find('th:eq(1)')
        .text(year + '-' + (year + 9))
        .end()
        .find('td');

      year -= 1;
      var currentYear = new Date().getFullYear(); // Get current year
      for (var i = -1; i < 11; i++) {
        var yearClasses = 'year inline-flex items-center justify-center w-[calc(25%-8px)] h-9 m-1 cursor-pointer hover:bg-blue-50 rounded-full transition-all duration-150 font-semibold text-sm border-2 border-transparent hover:border-blue-200 flex-shrink-0';

        if (i === -1 || i === 10) {
          yearClasses += ' old text-gray-400';
        } else {
          yearClasses += ' text-gray-800';
        }

        // Disable previous years
        if (year < currentYear) {
          yearClasses += ' disabled cursor-not-allowed opacity-40 hover:bg-transparent';
        }

        if (currentYear === year) {
          yearClasses += ' active !bg-blue-600 !text-white hover:!bg-blue-700 shadow-md !border-blue-600';
        }

        html += '<span class="' + yearClasses + '">' + year + '</span>';
        year += 1;
      }
      yearCont.html(html);
    },

    click: function(e) {
      e.stopPropagation();
      e.preventDefault();
      var target = $(e.target).closest('div.day, span, td, th');

      if (target.length === 1) {
        switch (target[0].nodeName.toLowerCase()) {
          case 'th':
            var classList = target[0].className.split(' ');
            var mainClass = classList[0];

            switch (mainClass) {
              case 'switch':
                this.showMode(1);
                break;
              case 'prev':
              case 'next':
                var isPrev = mainClass === 'prev';
                this.viewDate['set' + DPGlobal.modes[this.viewMode].navFnc].call(
                  this.viewDate,
                  this.viewDate['get' + DPGlobal.modes[this.viewMode].navFnc].call(this.viewDate) +
                  DPGlobal.modes[this.viewMode].navStep * (isPrev ? -1 : 1)
                );
                this.fill();
                this.set();
                break;
            }
            break;

          case 'span':
            // Check if the span is disabled (for past years/months)
            if (target.hasClass('disabled')) {
              break; // Don't do anything if disabled
            }

            if (target.hasClass('month')) {
              var month = target.parent().find('span').index(target);
              this.viewDate.setMonth(month);
            } else if (target.hasClass('year')) {
              var year = parseInt(target.text(), 10) || 0;
              this.viewDate.setFullYear(year);
            }

            // Don't trigger changeDate for year/month selection
            // Only navigate to the next view
            this.showMode(-1);
            this.fill();

            // Only set the date if we're in day view after navigation
            if (this.viewMode === 0) {
              this.set();
            }
            break;

          case 'div':
          case 'td':
            var dayDiv = target.is('div') && target.hasClass('day') ? target : target.find('div.day');
            if (dayDiv.length && dayDiv.hasClass('day') && !dayDiv.hasClass('disabled')) {
              var day = parseInt(dayDiv.text(), 10) || 1;
              var month = this.viewDate.getMonth();

              if (dayDiv.hasClass('old')) {
                month -= 1;
              } else if (dayDiv.hasClass('new')) {
                month += 1;
              }

              var year = this.viewDate.getFullYear();
              this.date = new Date(year, month, day, 0, 0, 0, 0);
              this.viewDate = new Date(year, month, Math.min(28, day), 0, 0, 0, 0);
              this.fill();
              this.set();
              this.element.trigger({
                type: 'changeDate',
                date: this.date,
                viewMode: DPGlobal.modes[this.viewMode].clsName
              });
            }
            break;
        }
      }
    },

    mousedown: function(e) {
      e.stopPropagation();
      e.preventDefault();
    },

    showMode: function(dir) {
      if (dir) {
        this.viewMode = Math.max(this.minViewMode, Math.min(2, this.viewMode + dir));
      }
      this.picker.find('>div').addClass('hidden').filter('.datepicker-' + DPGlobal.modes[this.viewMode].clsName).removeClass('hidden');
    }
  };

  $.fn.datepicker = function(option, val) {
    return this.each(function() {
      var $this = $(this),
          data = $this.data('datepicker'),
          options = typeof option === 'object' && option;

      if (!data) {
        $this.data('datepicker', (data = new Datepicker(this, $.extend({}, $.fn.datepicker.defaults, options))));
      }

      if (typeof option === 'string') data[option](val);
    });
  };

  $.fn.datepicker.defaults = {
    onRender: function(date) {
      return '';
    }
  };

  $.fn.datepicker.Constructor = Datepicker;

  var DPGlobal = {
    modes: [
      {clsName: 'days', navFnc: 'Month', navStep: 1},
      {clsName: 'months', navFnc: 'FullYear', navStep: 1},
      {clsName: 'years', navFnc: 'FullYear', navStep: 10}
    ],

    dates: {
      days: ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"],
      daysShort: ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"],
      daysMin: ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa", "Su"],
      months: ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"],
      monthsShort: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"]
    },

    isLeapYear: function(year) {
      return (((year % 4 === 0) && (year % 100 !== 0)) || (year % 400 === 0));
    },

    getDaysInMonth: function(year, month) {
      return [31, (DPGlobal.isLeapYear(year) ? 29 : 28), 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][month];
    },

    parseFormat: function(format) {
      var separator = format.match(/[.\/\-\s].*?/),
          parts = format.split(/\W+/);

      if (!separator || !parts || parts.length === 0) {
        throw new Error("Invalid date format.");
      }

      return {separator: separator, parts: parts};
    },

    parseDate: function(date, format) {
      var parts = date.split(format.separator),
          date = new Date(),
          val;

      date.setHours(0);
      date.setMinutes(0);
      date.setSeconds(0);
      date.setMilliseconds(0);

      if (parts.length === format.parts.length) {
        var year = date.getFullYear(),
            day = date.getDate(),
            month = date.getMonth();

        for (var i = 0, cnt = format.parts.length; i < cnt; i++) {
          val = parseInt(parts[i], 10) || 1;
          switch (format.parts[i]) {
            case 'dd':
            case 'd':
              day = val;
              date.setDate(val);
              break;
            case 'mm':
            case 'm':
              month = val - 1;
              date.setMonth(val - 1);
              break;
            case 'yy':
              year = 2000 + val;
              date.setFullYear(2000 + val);
              break;
            case 'yyyy':
              year = val;
              date.setFullYear(val);
              break;
          }
        }
        date = new Date(year, month, day, 0, 0, 0);
      }
      return date;
    },

    formatDate: function(date, format) {
      var val = {
        d: date.getDate(),
        m: date.getMonth() + 1,
        yy: date.getFullYear().toString().substring(2),
        yyyy: date.getFullYear()
      };
      val.dd = (val.d < 10 ? '0' : '') + val.d;
      val.mm = (val.m < 10 ? '0' : '') + val.m;

      var date = [];
      for (var i = 0, cnt = format.parts.length; i < cnt; i++) {
        date.push(val[format.parts[i]]);
      }
      return date.join(format.separator);
    },

    formatStorageDate: function(date) {
      return DPGlobal.formatDate(date, {separator: '-', parts: ['dd', 'mm', 'yyyy']});
    },

    formatDisplayDate: function(date) {
      // Short month (Nov 23, 2026) so split date fields don't truncate
      var month = DPGlobal.dates.monthsShort[date.getMonth()];
      var day = date.getDate();
      var paddedDay = (day < 10 ? '0' : '') + day;
      return month + ' ' + paddedDay + ', ' + date.getFullYear();
    },

    monthNameToIndex: function(name) {
      var key = String(name || '').toLowerCase();
      for (var i = 0; i < 12; i++) {
        if (DPGlobal.dates.months[i].toLowerCase() === key || DPGlobal.dates.monthsShort[i].toLowerCase() === key) {
          return i;
        }
      }
      return -1;
    },

    parseDisplayDate: function(dateStr) {
      if (!dateStr) return null;
      var match = String(dateStr).trim().match(/^([A-Za-z]+)\s+(\d{1,2}),\s*(\d{4})$/);
      if (!match) return null;
      var month = DPGlobal.monthNameToIndex(match[1]);
      if (month < 0) return null;
      return new Date(parseInt(match[3], 10), month, parseInt(match[2], 10), 0, 0, 0, 0);
    },

    parseFlexibleDate: function(dateStr, storageFormat) {
      if (!dateStr) return null;
      var displayDate = DPGlobal.parseDisplayDate(dateStr);
      if (displayDate) return displayDate;
      try {
        return DPGlobal.parseDate(dateStr, storageFormat);
      } catch (e) {
        return null;
      }
    },

    headTemplate: '<thead class="bg-slate-50">' +
      '<tr>' +
      '<th class="prev text-center py-3 px-3 cursor-pointer hover:bg-blue-100 rounded-full transition-all duration-150 w-10">' +
      '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block text-gray-700"><path d="M15 18l-6-6 6-6"/></svg>' +
      '</th>' +
      '<th colspan="5" class="switch text-center py-3 px-3 cursor-pointer hover:bg-blue-100 rounded-full transition-all duration-150 font-bold text-base text-gray-900"></th>' +
      '<th class="next text-center py-3 px-3 cursor-pointer hover:bg-blue-100 rounded-full transition-all duration-150 w-10">' +
      '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block text-gray-700"><path d="M9 18l6-6-6-6"/></svg>' +
      '</th>' +
      '</tr>' +
      '</thead>',

    contTemplate: '<tbody><tr><td colspan="7" class="p-2"></td></tr></tbody>'
  };

  DPGlobal.template = '<div class="datepicker hidden bg-white rounded-lg shadow-2xl border border-gray-200 p-3 transition-opacity duration-200 ease-out" style="max-width: 320px;">' +
    '<div class="datepicker-mobile-header items-center justify-between mb-3 pb-3 border-b border-gray-200" style="display:none;">' +
      '<span class="text-base font-semibold text-gray-900">Select date</span>' +
      '<button type="button" class="datepicker-close w-9 h-9 flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-500">' +
        '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
      '</button>' +
    '</div>' +
    '<div class="datepicker-days">' +
    '<table class="w-full border-collapse">' +
    DPGlobal.headTemplate +
    '<tbody></tbody>' +
    '</table>' +
    '</div>' +
    '<div class="datepicker-months hidden" style="max-width: 280px;">' +
    '<table class="w-full border-collapse">' +
    DPGlobal.headTemplate +
    DPGlobal.contTemplate +
    '</table>' +
    '</div>' +
    '<div class="datepicker-years hidden" style="max-width: 280px;">' +
    '<table class="w-full border-collapse">' +
    DPGlobal.headTemplate +
    DPGlobal.contTemplate +
    '</table>' +
    '</div>' +
    '</div>';

  window.SearchDate = {
    getValue: function(el) {
      var $el = $(el);
      var stored = $el.data('date-value');
      if (stored) return stored;
      var val = ($el.val() || '').trim();
      if (!val) return '';
      var storageFormat = {separator: '-', parts: ['dd', 'mm', 'yyyy']};
      var parsed = DPGlobal.parseFlexibleDate(val, storageFormat);
      return parsed ? DPGlobal.formatStorageDate(parsed) : val;
    }
  };

}(window.jQuery);

// ===============================

$(document).ready(function() {
    var now = new Date();
    now.setHours(0, 0, 0, 0);

    $('.search-date, .CruiseDeparture').datepicker({
        format: 'dd-mm-yyyy',
        useDisplayFormat: true,
        onRender: function(date) {
            return date.valueOf() < now.valueOf() ? 'disabled' : '';
        }
    }).on('changeDate', function(ev){
        $(this).datepicker('hide');
    });

    $('.dp').not('.search-date').datepicker({
        format: 'dd-mm-yyyy',
        onRender: function(date) {
            return date.valueOf() < now.valueOf() ? 'disabled' : '';
        }
    }).on('changeDate', function(ev){
        $(this).datepicker('hide');
    });
});

if ($('.HotelCheckin').length && $('.HotelCheckout').length) {
    var now = new Date();
    now.setHours(0, 0, 0, 0);

    var updateScope = function(elem, scopeKey, storageKey) {
        var val = elem.val();
        // Removed Angular code - just use SET function if available
        if (val && typeof SET === 'function') {
            SET(storageKey, val);
        }
    };

    var hotelCheckin = $('.HotelCheckin').datepicker({
        format: 'dd-mm-yyyy',
        useDisplayFormat: true,
        onRender: function(date) {
            return date.valueOf() < now.valueOf() ? 'disabled' : '';
        }
    }).on('changeDate', function(ev) {
        var nextDay = new Date(ev.date);
        nextDay.setDate(nextDay.getDate() + 1);
        hotelCheckout.setValue(nextDay);
        hotelCheckout.fill();
        hotelCheckin.hide();
        $('.HotelCheckout')[0].focus();
        updateScope($(this), 'hotelsCheckinDate', 'hotelsCheckinDate');
    }).data('datepicker');

    var hotelCheckout = $('.HotelCheckout').datepicker({
        format: 'dd-mm-yyyy',
        useDisplayFormat: true,
        onRender: function(date) {
            return (hotelCheckin && hotelCheckin.date && date.valueOf() <= hotelCheckin.date.valueOf()) ? 'disabled' : '';
        }
    }).on('changeDate', function(ev) {
        hotelCheckout.hide();
        updateScope($(this), 'hotelsCheckoutDate', 'hotelsCheckoutDate');
    }).data('datepicker');
}

// FLIGHTS ==================================================

if ($('.FlightsDeparture').length && $('.FlightsArrival').length) {
    var now = new Date();
    now.setHours(0, 0, 0, 0);

    var updateScope = function(elem, scopeKey, storageKey) {
        var val = elem.val();
        // Removed Angular code - just use SET function if available
        if (val && typeof SET === 'function') {
            SET(storageKey, val);
        }
    };

    var flightCheckin = $('.FlightsDeparture').datepicker({
        format: 'dd-mm-yyyy',
        useDisplayFormat: true,
        onRender: function(date) {
            return date.valueOf() < now.valueOf() ? 'disabled' : '';
        }
    }).on('changeDate', function(ev) {
        var returnPicker = document.getElementById('return_date_picker');
        var isRoundtrip = returnPicker && returnPicker.style.display !== 'none';
        if (isRoundtrip && flightCheckout) {
            var nextDay = new Date(ev.date);
            nextDay.setDate(nextDay.getDate() + 1);
            flightCheckout.setValue(nextDay);
            flightCheckout.fill();
            if ($('.FlightsArrival').length) {
                $('.FlightsArrival')[0].focus();
            }
        }
        flightCheckin.hide();
        updateScope($(this), 'FlightsDeparture', 'FlightsArrival');
    }).data('datepicker');

    var flightCheckout = $('.FlightsArrival').datepicker({
        format: 'dd-mm-yyyy',
        useDisplayFormat: true,
        onRender: function(date) {
            return (flightCheckin && flightCheckin.date && date.valueOf() <= flightCheckin.date.valueOf()) ? 'disabled' : '';
        }
    }).on('changeDate', function(ev) {
        flightCheckout.hide();
        updateScope($(this), 'FlightsDeparture', 'FlightsArrival');
    }).data('datepicker');

    setTimeout(function() {
        var returnPickerEl = document.getElementById('return_date_picker');
        if (returnPickerEl && returnPickerEl.style.display !== 'none' && typeof window.ensureRoundtripReturnDate === 'function') {
            window.ensureRoundtripReturnDate();
        }
    }, 50);
}

// FERRIES ==================================================

if ($('.FerriesDeparture').length) {
    var ferriesNow = new Date();
    ferriesNow.setHours(0, 0, 0, 0);

    // WRITE yyyy-mm-dd TO HIDDEN INPUT SO search() always gets clean ISO date
    function _ferriesSetISO(inputId, dateObj) {
        var el = document.getElementById(inputId);
        if (!el || !dateObj) return;
        var y  = dateObj.getFullYear();
        var m  = String(dateObj.getMonth() + 1).padStart(2, '0');
        var d  = String(dateObj.getDate()).padStart(2, '0');
        el.value = y + '-' + m + '-' + d;
    }

    var ferriesDep = $('.FerriesDeparture').datepicker({
        format: 'dd-mm-yyyy',
        useDisplayFormat: true,
        onRender: function(date) {
            return date.valueOf() < ferriesNow.valueOf() ? 'disabled' : '';
        }
    }).on('changeDate', function(ev) {
        _ferriesSetISO('ferries_dep_iso', ev.date);

        // AUTO-ADVANCE RETURN DATE AND OPEN ITS PICKER IF ROUND-TRIP IS ACTIVE
        var returnPanel = document.getElementById('ferries_return_date_picker');
        if (ferriesRet && returnPanel && returnPanel.style.display !== 'none') {
            var nextDay = new Date(ev.date);
            nextDay.setDate(nextDay.getDate() + 1);
            ferriesRet.setValue(nextDay);
            ferriesRet.fill();
            _ferriesSetISO('ferries_ret_iso', nextDay);
            ferriesDep.hide();
            ferriesRet.show(); // OPEN RETURN DATEPICKER AUTOMATICALLY
        } else {
            ferriesDep.hide();
        }
    }).data('datepicker');

    // SEED INITIAL ISO FROM DISPLAYED VALUE (so first search without changing date works)
    (function() {
        var dp = ferriesDep;
        if (dp && dp.date) {
            _ferriesSetISO('ferries_dep_iso', dp.date);
        }
    })();

    var ferriesRet = $('.FerriesReturn').length ? $('.FerriesReturn').datepicker({
        format: 'dd-mm-yyyy',
        useDisplayFormat: true,
        onRender: function(date) {
            return (ferriesDep && ferriesDep.date && date.valueOf() <= ferriesDep.date.valueOf()) ? 'disabled' : '';
        }
    }).on('changeDate', function(ev) {
        _ferriesSetISO('ferries_ret_iso', ev.date);
        ferriesRet.hide();
    }).data('datepicker') : null;
}

// CARS ==================================================

if ($('.CarsPickup').length && $('.CarsReturn').length) {
    var now = new Date();
    now.setHours(0, 0, 0, 0);

    var updateScope = function(elem, scopeKey, storageKey) {
        var val = elem.val();
        // Removed Angular code - just use SET function if available
        if (val && typeof SET === 'function') {
            SET(storageKey, val);
        }
    };

    var carsPickup = $('.CarsPickup').datepicker({
        format: 'dd-mm-yyyy',
        useDisplayFormat: true,
        onRender: function(date) {
            return date.valueOf() < now.valueOf() ? 'disabled' : '';
        }
    }).on('changeDate', function(ev) {
        var nextDay = new Date(ev.date);
        nextDay.setDate(nextDay.getDate() + 1);
        carsReturn.setValue(nextDay);
        carsReturn.fill();
        carsPickup.hide();
        $('.CarsReturn')[0].focus();
        updateScope($(this), 'carsPickupDate', 'carsPickupDate');
    }).data('datepicker');

    var carsReturn = $('.CarsReturn').datepicker({
        format: 'dd-mm-yyyy',
        useDisplayFormat: true,
        onRender: function(date) {
            return (carsPickup && carsPickup.date && date.valueOf() <= carsPickup.date.valueOf()) ? 'disabled' : '';
        }
    }).on('changeDate', function(ev) {
        carsReturn.hide();
        updateScope($(this), 'carsReturnDate', 'carsReturnDate');
    }).data('datepicker');

    // TIME PICKERS
    if ($('.CarsPickupTime').length) $('.CarsPickupTime').timepicker({ defaultHour: 10, defaultMinute: 0 });
    if ($('.CarsReturnTime').length) $('.CarsReturnTime').timepicker({ defaultHour: 10, defaultMinute: 0 });
}

// VISA =====================================================

if ($('.VisaTravel').length) {
    var now = new Date();
    now.setHours(0, 0, 0, 0);

    var updateScope = function(elem, scopeKey, storageKey) {
        var val = elem.val();
        // Removed Angular code - just use SET function if available
        if (val && typeof SET === 'function') {
            SET(storageKey, val);
        }
    };

    $('.VisaTravel').datepicker({
        format: 'dd-mm-yyyy',
        useDisplayFormat: true,
        onRender: function(date) {
            return date.valueOf() < now.valueOf() ? 'disabled' : '';
        }
    }).on('changeDate', function(ev) {
        $(this).datepicker('hide');
        updateScope($(this), 'visaTravelDate', 'visaTravelDate');
    });
}

// CRUISES =================================================

if ($('.CruiseDeparture').length) {
    var now = new Date();
    now.setHours(0, 0, 0, 0);

    var updateScope = function(elem, scopeKey, storageKey) {
        var val = elem.val();
        // Removed Angular code - just use SET function if available
        if (val && typeof SET === 'function') {
            SET(storageKey, val);
        }
    };

    $('.CruiseDeparture').datepicker({
        format: 'dd-mm-yyyy',
        useDisplayFormat: true,
        onRender: function(date) {
            return date.valueOf() < now.valueOf() ? 'disabled' : '';
        }
    }).on('changeDate', function(ev) {
        $(this).datepicker('hide');
        updateScope($(this), 'cruiseDepartureDate', 'cruiseDepartureDate');
    });
}

