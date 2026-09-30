/*
 * Plateforme SALW : bouton « Prendre rendez-vous » à coller sur le site d'un client.
 *   <script src="https://…/p/bouton.js" data-url="https://…/p/rdv.php?c=…" data-couleur="#6b2150"
 *           data-texte="Prendre rendez-vous" data-position="droite" defer></script>
 * Aucun cookie, aucune donnée collectée : le bouton est un simple lien vers la page de réservation.
 */
(function () {
  "use strict";
  var s = document.currentScript;
  if (!s) return;
  var url = s.getAttribute("data-url") || "";
  if (!/^https?:\/\//.test(url)) return;
  var couleur = /^#[0-9a-f]{6}$/i.test(s.getAttribute("data-couleur") || "") ? s.getAttribute("data-couleur") : "#6b2150";
  var gauche = s.getAttribute("data-position") === "gauche";

  function poser() {
    if (document.getElementById("salw-rdv")) return;
    var a = document.createElement("a");
    a.id = "salw-rdv";
    a.href = url;
    a.textContent = s.getAttribute("data-texte") || "Prendre rendez-vous";
    a.style.cssText = "position:fixed;bottom:20px;" + (gauche ? "left" : "right") + ":20px;z-index:2147483000;display:inline-flex;align-items:center;gap:8px;" +
      "background:" + couleur + ";color:#fff;padding:13px 22px;border-radius:999px;font:700 16px/1.2 system-ui,-apple-system,'Segoe UI',sans-serif;" +
      "text-decoration:none;box-shadow:0 10px 28px -10px rgba(0,0,0,.55);max-width:calc(100vw - 40px)";
    var ico = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    ico.setAttribute("width", "18");
    ico.setAttribute("height", "18");
    ico.setAttribute("viewBox", "0 0 20 20");
    ico.setAttribute("aria-hidden", "true");
    ico.innerHTML = '<path d="M3.5 5h13v11.5h-13zM3.5 8.5h13M7 3v4M13 3v4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>';
    a.insertBefore(ico, a.firstChild);
    a.addEventListener("focus", function () { a.style.outline = "3px solid #E8A07A"; a.style.outlineOffset = "3px"; });
    a.addEventListener("blur", function () { a.style.outline = "none"; });
    document.body.appendChild(a);
  }
  if (document.body) poser(); else document.addEventListener("DOMContentLoaded", poser);
})();
