import Extend from 'flarum/common/extenders';
import { loadChunk } from './util';

export default [
  // The progress page is in the lazy chunk, with the core page parts it draws in.
  new Extend.Routes().add('spoiler-shield', '/spoilers', (() => loadChunk().then(async (c) => (await c.loadCore(), c))) as any),
];
