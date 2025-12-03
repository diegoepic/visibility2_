// TTS en es-CL con fallback es-ES. Devuelve una función cancel() para cortar.
export function speak(text: string, rate=1){
  if (!("speechSynthesis" in window)) return () => {};
  const u = new SpeechSynthesisUtterance(text);
  u.lang = "es-CL";
  const v = speechSynthesis.getVoices().find(v => /es\-CL|es\-ES/i.test(v.lang));
  if (v) u.voice = v;
  u.rate = rate;
  speechSynthesis.cancel();
  speechSynthesis.speak(u);
  return () => speechSynthesis.cancel();
}
