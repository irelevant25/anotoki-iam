/**
 * Hands the browser a file to save, made here: the text came through the API
 * under the session's cookie, and the address the browser saves from is a
 * blob's - nothing of the API's stands in an address a person could pass on.
 */
export function saveFile(name: string, text: string, type: string): void {
  const url = URL.createObjectURL(new Blob([text], { type }));
  const link = document.createElement('a');
  link.href = url;
  link.download = name;
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
