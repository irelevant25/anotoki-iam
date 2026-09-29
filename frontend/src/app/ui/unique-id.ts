let counter = 0;

/**
 * An id no other element on the page has, for tying a label, a hint and an
 * error to their input. A counter is enough: ids only need to be unique within
 * the one document this app renders into.
 */
export function uniqueId(prefix: string): string {
  counter += 1;
  return `${prefix}-${counter}`;
}
