/**
 * 24-hour analog clock picker (hours + minutes, no seconds).
 */
export class ClockPicker {
    constructor(container, { value = '', onChange = null }) {
        this.container = container;
        this.onChange = onChange;
        this.mode = 'hour';
        this.hours = 8;
        this.minutes = 0;
        this.render();
        this.setValue(value || '08:00');
        this.bind();
    }

    setValue(value) {
        const normalized = this.normalize(value) || '08:00';
        const [h, m] = normalized.split(':');
        this.hours = Number.parseInt(h, 10);
        this.minutes = Number.parseInt(m, 10);
        if (this.hourHand) {
            this.updateHands();
            this.updateDigital();
        }
    }

    getValue() {
        return `${String(this.hours).padStart(2, '0')}:${String(this.minutes).padStart(2, '0')}`;
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
                <div class="clock-picker__mode">
                    <button type="button" class="clock-picker__mode-btn is-active" data-mode="hour">HH</button>
                    <button type="button" class="clock-picker__mode-btn" data-mode="minute">MM</button>
                </div>
                <div class="clock-picker__digital" aria-live="polite">08:00</div>
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
        this.faceEl = this.container.querySelector('.clock-picker__face');
        this.hourHand = this.container.querySelector('.clock-picker__hand--hour');
        this.minuteHand = this.container.querySelector('.clock-picker__hand--minute');
        this.ticksGroup = this.container.querySelector('.clock-picker__ticks');
        this.labelsGroup = this.container.querySelector('.clock-picker__labels');
        this.modeButtons = this.container.querySelectorAll('.clock-picker__mode-btn');

        this.drawFace();
        this.updateHands();
        this.updateDigital();
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

        for (let h = 0; h < 24; h += 3) {
            const display = String(h).padStart(2, '0');
            const angle = ((h / 24) * 360 - 90) * (Math.PI / 180);
            const radius = h % 6 === 0 ? 68 : 74;
            const x = 100 + radius * Math.cos(angle);
            const y = 100 + radius * Math.sin(angle);
            const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            text.setAttribute('x', String(x));
            text.setAttribute('y', String(y));
            text.setAttribute('class', 'clock-picker__label');
            text.textContent = display;
            this.labelsGroup.appendChild(text);
        }
    }

    bind() {
        this.modeButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                this.mode = btn.dataset.mode;
                this.modeButtons.forEach((b) => b.classList.toggle('is-active', b === btn));
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
                this.hours = Math.round(angle / 15) % 24;
            } else {
                this.minutes = Math.round(angle / 6) % 60;
            }

            this.updateHands();
            this.updateDigital();
            this.onChange?.(this.getValue());
        });
    }

    updateHands() {
        const hourAngle = ((this.hours % 24) / 24) * 360;
        const minuteAngle = (this.minutes / 60) * 360;
        this.hourHand.style.transform = `rotate(${hourAngle}deg)`;
        this.minuteHand.style.transform = `rotate(${minuteAngle}deg)`;
    }

    updateDigital() {
        this.digitalEl.textContent = this.getValue();
    }
}

export function mountClockPicker(container, options) {
    return new ClockPicker(container, options);
}
