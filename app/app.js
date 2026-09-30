/* Plateforme SALW Santé · espace de travail : menu mobile, confirmations, envoi auto, compteur SMS, QR. */
(function () {
  "use strict";
  var menu = document.getElementById("menu"), nav = document.getElementById("nav");
  if (menu && nav) {
    menu.addEventListener("click", function () { menu.setAttribute("aria-expanded", nav.classList.toggle("ouvert") ? "true" : "false"); });
  }
  document.querySelectorAll("form[data-confirmer]").forEach(function (f) {
    f.addEventListener("submit", function (e) { if (!window.confirm(f.getAttribute("data-confirmer"))) e.preventDefault(); });
  });
  document.querySelectorAll("[data-envoi-auto]").forEach(function (s) {
    s.addEventListener("change", function () { if (s.form) s.form.submit(); });
  });
  // Compteur de caractères des textes de SMS (160 par SMS sans accent spécial, 70 sinon).
  document.querySelectorAll("textarea[data-compteur]").forEach(function (t) {
    var sortie = t.parentNode.querySelector(".compteur");
    var gsm = /^[@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&'()*+,\-./0-9:;<=>?¡A-ZÄÖÑÜ§¿a-zäöñüà^{}\\[~\]|€]*$/;
    var maj = function () {
      var n = t.value.length, uni = !gsm.test(t.value);
      var seg = uni ? (n <= 70 ? 1 : Math.ceil(n / 67)) : (n <= 160 ? 1 : Math.ceil(n / 153));
      sortie.textContent = n + " caractères · " + seg + " SMS (hors lien)" + (uni ? " · caractères spéciaux : 70 par SMS" : "");
    };
    t.addEventListener("input", maj);
  });
  // Boutons « Copier » des codes à intégrer.
  document.querySelectorAll("[data-copier]").forEach(function (b) {
    b.addEventListener("click", function () {
      var t = document.getElementById(b.getAttribute("data-copier"));
      var fait = function () { b.textContent = "Copié ✓"; setTimeout(function () { b.textContent = "Copier"; }, 2000); };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(t.value).then(fait, function () { t.select(); });
      } else {
        t.select();
        try { document.execCommand("copy"); fait(); } catch (e) { /* le texte reste sélectionné : Ctrl+C */ }
      }
    });
  });
  var qr = document.querySelector("[data-qr]");
  if (qr) {
    var s = document.createElement("script");
    s.src = "https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js";
    s.onload = function () {
      /* global qrcode */
      var q = qrcode(0, "M");
      q.addData(qr.getAttribute("data-qr"));
      q.make();
      qr.innerHTML = q.createImgTag(parseInt(qr.getAttribute("data-qr-taille"), 10) || 4, 8, "Code QR");
      var nom = qr.getAttribute("data-qr-nom");
      if (nom) {
        var a = document.createElement("a");
        a.href = qr.querySelector("img").src;
        a.download = nom;
        a.className = "btn contour";
        a.textContent = "Télécharger le code QR";
        qr.appendChild(a);
      }
    };
    s.onerror = function () { qr.textContent = "Code QR indisponible : saisissez la clé."; };
    document.head.appendChild(s);
  }
})();
