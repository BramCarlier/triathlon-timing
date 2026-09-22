export function mayReplay(owner: number | undefined, current: number): boolean {
  return Number.isInteger(owner) && owner === current;
}
export function retryDisposition(status: number): 'retry' | 'review' {
  return status >= 500 || status === 408 || status === 429 ? 'retry' : 'review';
}
