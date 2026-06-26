/**
 * 12-hour analog clock picker with AM/PM — stores/emits 24-hour HH:MM.
 */
export class ClockPicker {
    constructor(container, { value = '', onChange = null, hints = {}, labels = {} } = {}) {
        this.container = container;
        this.onChange = onChange;
        this.hints = {
            chooseHour: hints.chooseHour || 'Tap the clock to choose hour',
            choosePeriod: hints.choosePeriod || 'Choose AM or PM',
            chooseMinutes: hints.chooseMinutes || 'Tap the clock to choose minutes',
        };
        this.labels = {
            hour: labels.hour || 'Hour',
            minute: labels.minute || 'Min',
            am: labels.am || 'AM',
            pm: labels.pm || 'PM',
        };
        this.mode = 'hour';
        this.hour12 = 8;
        this.minutes = 0;
        this.period = 'AM';
        this.defaultPeriod = 'AM';
        this.awaitingPeriod = false;
        this.render();
        this.setValue(value || '08:00');
        this.bind();
    }

    setDefaultPeriod(period) {
        this.defaultPeriod = period === 'PM' ? 'PM' : 'AM';
        if (this.periodButtons) {
            this.period = this.defaultPeriod;
            this.updatePeriodButtons();
            this.updateDigital();
        }
    }

    setValue(value) {
        const normalized = this.normalize(value) || '';
        if (!normalized) {
            this.period = this.defaultPeriod;
            this.hour12 = this.defaultPeriod === 'PM' ? 12 : 8;
            this.minutes = this.defaultPeriod === 'PM' ? 30 : 0;
            this.awaitingPeriod = false;
            if (this.hourHand) {
                this.setMode('hour');
                this.updateHands();
                this.updateDigital();
                this.updateHint();
                this.updatePeriodButtons();
            }
            return;
        }

        const [h, m] = normalized.split(':');
        const hours24 = Number.parseInt(h, 10);
        this.minutes = Number.parseInt(m, 10);
        this.period = hours24 >= 12 ? 'PM' : 'AM';
        this.hour12 = hours24 % 12 || 12;
        this.awaitingPeriod = false;
        if (this.hourHand) {
            this.setMode('hour');
            this.updateHands();
            this.updateDigital();
            this.updateHint();
            this.updatePeriodButtons();
        }
    }

    getValue() {
        return `${String(this.toHours24()).padStart(2, '0')}:${String(this.minutes).padStart(2, '0')}`;
    }

    toHours24() {
        if (this.period === 'AM') {
            return this.hour12 === 12 ? 0 : this.hour12;
        }
        return this.hour12 === 12 ? 12 : this.hour12 + 12;
    }

    normalize(value) {
        if (!value) {
            return '';
        }
        const match = String(value).trim().match(/^(\d{1,2}):(\d{2})/);
        if (!match) {
            return '';
        }
        const hours = Number.parseInt(match[1], 10);
        const minutes = Number.parseInt(match[2], 10);
        if (hours > 23 || minutes > 59) {
            return '';
        }
        return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
    }

    render() {
        this.container.innerHTML = `
            <div class="clock-picker">
                <div class="clock-picker__controls">
                    <div class="clock-picker__mode">
                        <button type="button" class="clock-picker__mode-btn is-active" data-mode="hour">${this.labels.hour}</button>
                        <button type="button" class="clock-picker__mode-btn" data-mode="minute">${this.labels.minute}</button>
                    </div>
                    <div class="clock-picker__period">
                        <button type="button" class="clock-picker__period-btn is-active" data-period="AM">${this.labels.am}</button>
                        <button type="button" class="clock-picker__period-btn" data-period="PM">${this.labels.pm}</button>
                    </div>
                </div>
                <div class="clock-picker__digital" aria-live="polite">08:00 AM</div>
                <p class="clock-picker__hint muted"></p>
                <div class="clock-picker__face" role="application" aria-label="Clock">
                    <svg class="clock-picker__svg" viewBox="0 0 200 200" aria-hidden="true">
                        <circle class="clock-picker__ring" cx="100" cy="100" r="92"></circle>
                        <g class="clock-picker__ticks"></g>
                        <g class="clock-picker__labels"></g>
                    </svg>
                    <div class="clock-picker__hands">
                        <div class="clock-picker__hand clock-picker__hand--hour"></div>
                        <div class="clock-picker__hand clock-picker__hand--minute"></div>
                        <div class="clock-picker__center"></div>
                    </div>
                </div>
            </div>
        `;

        this.digitalEl = this.container.querySelector('.clock-picker__digital');
        this.hintEl = this.container.querySelector('.clock-picker__hint');
        this.faceEl = this.container.querySelector('.clock-picker__face');
        this.hourHand = this.container.querySelector('.clock-picker__hand--hour');
        this.minuteHand = this.container.querySelector('.clock-picker__hand--minute');
        this.ticksGroup = this.container.querySelector('.clock-picker__ticks');
        this.labelsGroup = this.container.querySelector('.clock-picker__labels');
        this.modeButtons = this.container.querySelectorAll('.clock-picker__mode-btn');
        this.periodButtons = this.container.querySelectorAll('.clock-picker__period-btn');

        this.drawFace();
        this.updateHands();
        this.updateDigital();
        this.updateHint();
        this.updatePeriodButtons();
    }

    drawFace() {
        for (let i = 0; i < 60; i++) {
            const angle = (i * 6 - 90) * (Math.PI / 180);
            const outer = i % 5 === 0 ? 86 : 90;
            const inner = i % 5 === 0 ? 78 : 84;
            const x1 = 100 + inner * Math.cos(angle);
            const y1 = 100 + inner * Math.sin(angle);
            const x2 = 100 + outer * Math.cos(angle);
            const y2 = 100 + outer * Math.sin(angle);
            const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
            line.setAttribute('x1', String(x1));
            line.setAttribute('y1', String(y1));
            line.setAttribute('x2', String(x2));
            line.setAttribute('y2', String(y2));
            line.setAttribute('class', i % 5 === 0 ? 'clock-picker__tick clock-picker__tick--major' : 'clock-picker__tick');
            this.ticksGroup.appendChild(line);
        }

        for (let h = 1; h <= 12; h++) {
            const angle = ((h % 12) / 12) * 360 - 90;
            const rad = angle * (Math.PI / 180);
            const x = 100 + 68 * Math.cos(rad);
            const y = 100 + 68 * Math.sin(rad);
            const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            text.setAttribute('x', String(x));
            text.setAttribute('y', String(y));
            text.setAttribute('class', 'clock-picker__label');
            text.textContent = String(h);
            this.labelsGroup.appendChild(text);
        }
    }

    bind() {
        this.modeButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                this.setMode(btn.dataset.mode);
            });
        });

        this.periodButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                this.period = btn.dataset.period;
                this.awaitingPeriod = false;
                this.updatePeriodButtons();
                this.updateDigital();
                this.setMode('minute');
                this.updateHint();
                this.emitChange();
            });
        });

        this.faceEl.addEventListener('click', (event) => {
            const rect = this.faceEl.getBoundingClientRect();
            const cx = rect.left + rect.width / 2;
            const cy = rect.top + rect.height / 2;
            const dx = event.clientX - cx;
            const dy = event.clientY - cy;
            let angle = Math.atan2(dy, dx) * (180 / Math.PI) + 90;
            if (angle < 0) {
                angle += 360;
            }

            if (this.mode === 'hour') {
                const picked = Math.round(angle / 30) % 12;
                this.hour12 = picked === 0 ? 12 : picked;
                this.period = this.defaultPeriod;
                this.awaitingPeriod = false;
                this.updatePeriodButtons();
                this.setMode('minute');
                this.updateHands();
                this.updateDigital();
                this.updateHint();
                this.emitChange();
                return;
            }

            this.minutes = Math.round(angle / 6) % 60;
            this.awaitingPeriod = false;
            this.updateHands();
            this.updateDigital();
            this.updateHint();
            this.emitChange();
        });
    }

    setMode(mode) {
        this.mode = mode === 'minute' ? 'minute' : 'hour';
        this.modeButtons.forEach((btn) => {
            btn.classList.toggle('is-active', btn.dataset.mode === this.mode);
        });
        this.updateHint();
    }

    updatePeriodButtons() {
        this.periodButtons.forEach((btn) => {
            const isActive = btn.dataset.period === this.period;
            btn.classList.toggle('is-active', isActive);
            btn.classList.toggle('is-pending', this.awaitingPeriod && !isActive);
        });
    }

    updateHands() {
        const hourAngle = ((this.hour12 % 12) / 12) * 360;
        const minuteAngle = (this.minutes / 60) * 360;
        this.hourHand.style.transform = `rotate(${hourAngle}deg)`;
        this.minuteHand.style.transform = `rotate(${minuteAngle}deg)`;
    }

    updateDigital() {
        const minutePart = String(this.minutes).padStart(2, '0');
        this.digitalEl.textContent = `${this.hour12}:${minutePart} ${this.period}`;
    }

    updateHint() {
        if (!this.hintEl) {
            return;
        }

        if (this.awaitingPeriod) {
            this.hintEl.textContent = this.hints.choosePeriod;
            return;
        }

        if (this.mode === 'minute') {
            this.hintEl.textContent = this.hints.chooseMinutes;
            return;
        }

        this.hintEl.textContent = this.hints.chooseHour;
    }

    emitChange() {
        this.onChange?.(this.getValue());
    }
}

export function mountClockPicker(container, options) {
    return new ClockPicker(container, options);
}
