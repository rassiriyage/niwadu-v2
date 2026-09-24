export const NEW_PASSWORD_HELP = "Use at least 12 characters and no more than 72 UTF-8 bytes. Some characters use more than one byte.";

// Mirror the API's rules for newly set passwords; legacy sign-in stays unchanged.
export function newPasswordError(password: string): string | null {
  if (password.includes("\0")) return "Your password cannot contain a null character.";
  if ([...password].length < 12) return "Use at least 12 characters for your password.";
  if (new TextEncoder().encode(password).length > 72) return "Your password exceeds 72 UTF-8 bytes. Use a shorter password; some characters use more than one byte.";
  return null;
}
