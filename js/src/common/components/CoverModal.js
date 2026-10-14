import app from 'flarum/common/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';

import FramePicker from './FramePicker';
import { frameOf } from '../frame';

const t = (key, params) => app.translator.trans(`ernestdefoe-backdrop.lib.editor.${key}`, params);

/**
 * Choose, frame and remove a cover. Used for a member's cover on the forum and
 * for the default cover in the admin; the caller supplies the requests.
 *
 * Attrs:
 * - title
 * - cover    the current {url, x, y, zoom}, or null
 * - upload(FormData) → Promise
 * - reframe({x, y, zoom}) → Promise
 * - remove() → Promise
 */
export default class CoverModal extends Modal {
  oninit(vnode) {
    super.oninit(vnode);

    this.cover = this.attrs.cover || null;
    this.frame = frameOf(this.cover);
    this.file = null;
    this.preview = null;
  }

  className() {
    return 'BackdropModal Modal--large';
  }

  title() {
    return this.attrs.title;
  }

  onremove(vnode) {
    super.onremove(vnode);

    if (this.preview) URL.revokeObjectURL(this.preview);
  }

  content() {
    const url = this.preview || this.cover?.url;
    const overlay = app.forum.attribute('backdrop')?.overlay || 'gradient';

    return (
      <div className="Modal-body">
        {url ? (
          <FramePicker url={url} frame={this.frame} overlay={overlay} onchange={(frame) => (this.frame = frame)} />
        ) : (
          <button type="button" className="BackdropModal-empty" onclick={() => this.pick()}>
            {t('empty')}
          </button>
        )}

        <p className="helpText">{url ? t('hint') : t('formats')}</p>

        <input type="file" accept="image/jpeg,image/png,image/gif,image/webp" hidden onchange={(e) => this.choose(e.target.files[0])} />

        <div className="BackdropModal-actions">
          <Button className="Button" icon="fas fa-upload" onclick={() => this.pick()} disabled={this.loading}>
            {url ? t('replace') : t('choose')}
          </Button>
          {this.cover && !this.file && (
            <Button
              className="Button Button--danger"
              icon="fas fa-trash-alt"
              onclick={() => this.run(() => this.attrs.remove())}
              disabled={this.loading}
            >
              {t('remove')}
            </Button>
          )}
          <Button className="Button Button--primary" onclick={() => this.save()} loading={this.loading} disabled={!url}>
            {t('save')}
          </Button>
        </div>
      </div>
    );
  }

  pick() {
    this.$('input[type=file]')[0].click();
  }

  choose(file) {
    if (!file) return;

    if (this.preview) URL.revokeObjectURL(this.preview);

    this.file = file;
    this.preview = URL.createObjectURL(file);
    this.frame = frameOf(null);
    m.redraw();
  }

  save() {
    if (!this.file) return this.run(() => this.attrs.reframe(this.frame));

    const body = new FormData();
    body.append('cover', this.file);
    Object.entries(this.frame).forEach(([key, value]) => body.append(key, String(value)));

    this.run(() => this.attrs.upload(body));
  }

  run(request) {
    this.loading = true;
    m.redraw();

    request().then(
      () => this.hide(),
      (error) => {
        this.loaded();
        throw error;
      }
    );
  }
}
