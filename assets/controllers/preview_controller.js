import { Controller } from '@hotwired/stimulus';

/*
 * THE FILE PREVIEW — the behaviour half of storage-module's one shareable
 * component. Its markup half is templates/overlay/_preview.html.twig, which
 * carries this controller; its styles are public/preview.css.
 *
 * WHY AN OVERLAY AND NOT ONLY A PAGE. Browsing is comparing: you open one
 * photograph, then the next one on the same record, without losing the grid you
 * found them in. The arrows are the whole argument. The file's own page exists
 * too and shows the same facts, because a file must be linkable — you cannot
 * send somebody an overlay.
 *
 * THE OVERLAY IS FILLED FROM THE TRIGGER'S OWN ATTRIBUTES, so opening one costs
 * NO request; the only thing fetched is the ~400px picture, and even that comes
 * through the permission check. The contract those attributes speak is stated
 * once, in the partial's attrs() macro — this file only reads it.
 *
 * WHAT IS DELIBERATELY ABSENT. The guard — what THIS PERSON may do to this file
 * — is never drawn here. It is the owning record's answer about this person, it
 * is rendered where it is authoritative (the file's own page, linked from the
 * header), and an overlay that repeated a permission it had cached would
 * eventually repeat it wrongly.
 *
 * The listeners are on `document`, not on the controller's element: the triggers
 * are anywhere on the page and arrive after this connects (the widget library
 * composes previews), so binding to each one would leave the late ones dead.
 */

const NOIMG =
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" ' +
    'stroke-linecap="round" stroke-linejoin="round"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/>' +
    '<path d="M14 2v4a2 2 0 0 0 2 2h4"/></svg>';

const THUMB_WORD = {
    made: 'made',
    wait: 'being made',
    failed: 'could not be made',
    none: 'nothing to shrink',
};

/* A trigger opens the preview. Any module's markup may be one — that is the
   point of the component — so the selector is the contract's own marker and
   nothing surface-specific. */
const TRIGGER = '[data-f-preview]';

/* Where the step arrows look for the rest of the set: the nearest declared
   scope, else the nearest plate, else the page. */
const SCOPE = '[data-f-previewscope], [data-f-shapewrap], .c, body';

function esc(value) {
    return (value || '').replace(/[&<>"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[ch]);
}

function get(el, name) {
    return el.getAttribute('data-f-' + name) || '';
}

/* An instant, for the reader's own timezone.
 *
 * The overlay's side panel is written here rather than rendered by a template,
 * so it must emit the element the frame's scanner localises — a `<time>` with
 * the machine instant and the shape it wants — and not the server's wall-clock
 * as text. The frame watches for added nodes, so a row written after the page
 * loaded is localised like any other.
 *
 * The contract's formatted string stays the text inside it: that is what a
 * reader sees where the trigger carries no instant, or where Intl refuses. */
function instant(iso, reading, shape) {
    const text = esc(reading) || '&mdash;';
    if (!iso) {
        return text;
    }

    return '<time datetime="' + esc(iso) + '" data-localtime-format="' + shape + '">' + text + '</time>';
}

export default class extends Controller {
    connect() {
        /* THE OVERLAY LIVES ON THE BODY. Rendered where the page includes it, it
           sits inside `.page`, whose `z-index: 1` makes a stacking context (it
           keeps map panes under the sticky top bar), and a z-index only ranks
           within its own stacking context - so the sidebar drew over the
           overlay's left edge (#70). Moved to the body it ranks against the
           shell itself - as the shell's widget picker does (widgets.js,
           "the add-widget picker"). Stimulus disconnects and connects it again on the move,
           and that second connect finds it already there.
           https://developer.mozilla.org/en-US/docs/Web/CSS/Guides/Positioned_layout/Stacking_context */
        if (this.element.parentElement !== document.body) {
            document.body.appendChild(this.element);

            return;
        }
        this.openedFrom = null;
        this.pool = [];
        this.onClick = this.handleClick.bind(this);
        this.onKeydown = this.handleKeydown.bind(this);
        document.addEventListener('click', this.onClick);
        document.addEventListener('keydown', this.onKeydown);
    }

    disconnect() {
        document.removeEventListener('click', this.onClick);
        document.removeEventListener('keydown', this.onKeydown);
    }

    handleClick(event) {
        if (event.target.closest('[data-f-close]')) {
            this.close();

            return;
        }
        const stepper = event.target.closest('[data-f-step]');
        if (stepper) {
            this.step(parseInt(stepper.getAttribute('data-f-step'), 10));

            return;
        }
        /* A click inside the owner LINK is navigation, not a preview. */
        if (event.target.closest('a')) {
            return;
        }
        const trigger = event.target.closest(TRIGGER);
        if (trigger) {
            this.show(trigger);
        }
    }

    handleKeydown(event) {
        const isOpen = !this.element.hidden;
        if (isOpen && 'Escape' === event.key) {
            this.close();

            return;
        }
        if (isOpen && 'ArrowLeft' === event.key) {
            this.step(-1);

            return;
        }
        if (isOpen && 'ArrowRight' === event.key) {
            this.step(1);

            return;
        }
        if (!isOpen && ('Enter' === event.key || ' ' === event.key)) {
            const trigger = event.target.closest(TRIGGER);
            if (trigger) {
                event.preventDefault();
                this.show(trigger);
            }
        }
    }

    show(el) {
        /* The arrows walk THE SET YOU ARE LOOKING AT — the triggers the filters
           left visible, in the order they are drawn. */
        this.pool = Array.prototype.filter.call(
            el.closest(SCOPE).querySelectorAll(TRIGGER),
            (candidate) => !candidate.hidden,
        );
        this.openedFrom = el;

        this.element.querySelector('[data-f-ovname]').innerHTML =
            esc(get(el, 'name')) +
            ' &middot; <span class="d">' + esc(get(el, 'modlabel')) + ' &middot; ' + esc(get(el, 'rec')) + '</span>';

        /* No page for this file on this host: no link, rather than a link to
           nowhere. */
        const link = this.element.querySelector('[data-f-ovlink]');
        const detail = get(el, 'detail');
        link.hidden = '' === detail;
        link.setAttribute('href', '' === detail ? '#' : detail);

        const stage = this.element.querySelector('[data-f-ovstage]');
        stage.querySelectorAll(':scope > :not(.f-ovnav)').forEach((node) => node.remove());
        stage.insertAdjacentHTML('afterbegin', this.stage(el));

        this.element.querySelector('[data-f-ovside]').innerHTML = this.side(el);

        const many = this.pool.length > 1;
        this.element.querySelectorAll('.f-ovnav').forEach((button) => { button.hidden = !many; });

        this.element.hidden = false;
        this.element.querySelector('.f-ovbox').focus();
    }

    step(delta) {
        if (!this.openedFrom) {
            return;
        }
        const at = this.pool.indexOf(this.openedFrom);
        const next = this.pool[(at + delta + this.pool.length) % this.pool.length];
        if (next) {
            this.show(next);
        }
    }

    close() {
        this.element.hidden = true;
        if (this.openedFrom && this.openedFrom.focus) {
            this.openedFrom.focus();
        }
        this.openedFrom = null;
    }

    /* The picture, or the shape that says why there is none. A document, a track
       export, a photograph still in the queue and one the thumbnailer could not
       read are four different facts and must not look alike. */
    stage(el) {
        const img = get(el, 'img');
        if (img) {
            return '<img src="' + esc(img) + '" alt="' + esc(get(el, 'name')) + '">';
        }
        if ('wait' === get(el, 'thumb')) {
            return '<div class="noimg">' + NOIMG + 'the small picture is still being made<br>the original is here and downloadable</div>';
        }
        if ('failed' === get(el, 'thumb')) {
            return '<div class="noimg">' + NOIMG + 'no small picture could be made<br>the original is untouched</div>';
        }

        return '<div class="noimg">' + NOIMG + esc(get(el, 'mime')) + '<br>nothing to show small</div>';
    }

    owner(el) {
        const inner =
            '<i class="' + esc(get(el, 'mod')) + '"></i><span class="mod">' + esc(get(el, 'modlabel')) +
            '</span><span class="sepdot">&middot;</span>' + esc(get(el, 'rec'));
        if (!get(el, 'rechref')) {
            return '<span class="f-owner off">' + inner + '</span>';
        }

        return '<a class="f-owner" href="' + esc(get(el, 'rechref')) + '">' + inner + '</a>';
    }

    side(el) {
        const rows = [
            ['Owner', this.owner(el)],
            ['Taken', '<span class="mono val">' + instant(get(el, 'taken-at'), get(el, 'taken'), 'daystamp') + '</span>'],
            ['Arrived', '<span class="mono val">' + instant(get(el, 'uploaded-at'), get(el, 'uploaded'), 'daystamp') + '</span>'],
            ['Size', '<span class="mono val">' + esc(get(el, 'size')) + '</span>'],
            ['Type', '<span class="mono val">' + esc(get(el, 'mime')) + '</span>'],
            ['Its key', '<span class="mono val">' + esc(get(el, 'key')) + '</span>'],
            ['Small picture', '<span class="f-th ' + esc(get(el, 'thumb')) + '">' +
                (THUMB_WORD[get(el, 'thumb')] || '—') + '</span>'],
            ['Who can see it', '<span class="mono val">anyone who can see ' + esc(get(el, 'rec')) + '</span>'],
        ];
        const caption = get(el, 'caption');

        /* WHAT YOU MAY DO is deliberately links and no verdict. Whether this file
           may be removed is the owning record's answer about this person, and it
           is given on the file's own page — never cached into a trigger. Where
           the host ships no such page, the two honest links remain. */
        const acts = '<div class="f-acts">' +
            '<a class="f-act" href="' + esc(get(el, 'original')) + '">Open the original</a>' +
            (get(el, 'rechref') ? '<a class="f-act" href="' + esc(get(el, 'rechref')) + '">Open ' + esc(get(el, 'rec')) + '</a>' : '') +
            (get(el, 'detail') ? '<a class="f-act" href="' + esc(get(el, 'detail')) + '">What you may do &rarr;</a>' : '') +
            '</div>';

        return '<h3>What this file is</h3>' +
            (caption
                ? '<p class="use" style="margin-top:2px">' + esc(caption) + '</p>'
                : '<p class="use" style="margin-top:2px">No caption &mdash; a caption belongs to the record, not to the file.</p>') +
            rows.map((row) => '<div class="rln"><span>' + row[0] + '</span><span>' + row[1] + '</span></div>').join('') +
            '<h3>What you may do</h3>' + acts;
    }
}