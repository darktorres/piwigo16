/**
 * Coordinates rating.ts's own real DOM upgrade (`makeNiceRatingForm()`)
 * with picture.ts, the one page whose own markup needs it, when neither
 * file's load order relative to the other is guaranteed by the module
 * graph -- unlike switchbox.ts's own former queue (P51-H, retired
 * outright once both real pushers turned out to always import it
 * first), picture.ts and rating.ts share no `import` edge with each
 * other at all, so this queue is real, necessary coordination, not
 * dead code. Whichever of the two runs first queues via
 * pushRatingAutoQueue(); the other, running second, calls
 * drainRatingAutoQueue() once to both register itself as the live
 * handler and flush anything already queued.
 */

export interface PwgRatingResult {
  score: number;
  count: number;
  average?: number;
}

export interface PwgRatingOptions {
  rootUrl: string;
  image_id: string | number;
  onSuccess?: (result: PwgRatingResult) => void;
  updateRateElement?: HTMLElement;
  updateRateText?: string;
  ratingSummaryElement?: HTMLElement;
  ratingSummaryText?: string;
}

let handler: ((opts: PwgRatingOptions) => void) | null = null;
const queue: PwgRatingOptions[] = [];

export function pushRatingAutoQueue(opts: PwgRatingOptions): void {
  if (handler !== null) {
    handler(opts);
  } else {
    queue.push(opts);
  }
}

export function drainRatingAutoQueue(
  onEach: (opts: PwgRatingOptions) => void,
): void {
  handler = onEach;
  for (const opts of queue.splice(0, queue.length)) {
    onEach(opts);
  }
}
