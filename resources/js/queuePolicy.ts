/** Owner of a saved tap: an account id, or a race-day link scope such as "link:<token>". */
export type QueueOwner = number | string;

export function mayReplay(owner: QueueOwner | undefined, current: QueueOwner): boolean {
  if (owner === undefined || owner === null) return false;
  if (typeof owner === 'number' && !Number.isInteger(owner)) return false;
  return owner === current;
}

export function retryDisposition(status: number): 'retry' | 'review' {
  return status >= 500 || status === 408 || status === 429 ? 'retry' : 'review';
}
