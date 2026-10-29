// Browser side of passkeys: converts between the server's base64url JSON and the WebAuthn API's ArrayBuffers.

const toBuf = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(s.length / 4) * 4, '=')), (c) => c.charCodeAt(0)).buffer;
const toText = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const descriptors = (list) => (list || []).map((c) => ({ ...c, id: toBuf(c.id) }));

export const passkeysSupported = () => !!(window.PublicKeyCredential && navigator.credentials);

/** Asks the device to make a passkey; resolves with the fields the server checks. */
export async function createPasskey(o) {
  const cred = await navigator.credentials.create({ publicKey: { ...o, challenge: toBuf(o.challenge), user: { ...o.user, id: toBuf(o.user.id) }, excludeCredentials: descriptors(o.excludeCredentials) } });
  return { clientDataJSON: toText(cred.response.clientDataJSON), attestationObject: toText(cred.response.attestationObject) };
}

/** Asks the device to sign in with one of the person's passkeys. */
export async function usePasskey(o) {
  const cred = await navigator.credentials.get({ publicKey: { ...o, challenge: toBuf(o.challenge), allowCredentials: descriptors(o.allowCredentials) } });
  return { id: cred.id, clientDataJSON: toText(cred.response.clientDataJSON), authenticatorData: toText(cred.response.authenticatorData), signature: toText(cred.response.signature) };
}
