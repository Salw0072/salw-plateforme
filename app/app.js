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
    // Même conversion qu'à l'envoi (sms_gsm) : â ê î ô û ç œ « » ’ … deviennent des caractères standard.
    var conv = { "â": "a", "ê": "e", "ë": "e", "î": "i", "ï": "i", "ô": "o", "û": "u", "ç": "c", "ÿ": "y", "À": "A", "Â": "A", "È": "E", "Ê": "E", "Ë": "E", "Î": "I", "Ï": "I", "Ô": "O", "Ù": "U", "Û": "U",
      "œ": "oe", "Œ": "OE", "°": "o", "«": "\"", "»": "\"", "“": "\"", "”": "\"", "‘": "'", "’": "'", "…": "...", "–": "-", "—": "-", " ": " ", " ": " " };
    var maj = function () {
      var v = t.value.replace(/[âêëîïôûçÿÀÂÈÊËÎÏÔÙÛœŒ°«»“”‘’…–—  ]/g, function (c) { return conv[c]; });
      // Estimation du message réel : chaque variable remplacée par une longueur type (le lien fait environ 73 caractères).
      var types = { lien: 73, structure: 25, date: 19, heure: 5, prenom: 9, adresse: 30, devis: 25, praticien: 15 };
      v = v.replace(/\{([a-z]+)\}/g, function (x, k) { return new Array((types[k] || x.length) + 1).join("x"); });
      var n = v.length, uni = !gsm.test(v);
      var seg = uni ? (n <= 70 ? 1 : Math.ceil(n / 67)) : (n <= 160 ? 1 : Math.ceil(n / 153));
      sortie.textContent = "Environ " + n + " caractères une fois envoyé · " + seg + " SMS facturé" + (seg > 1 ? "s" : "") + (seg > 1 ? " : raccourcissez pour tenir en 1 SMS" : "") + (uni ? " · caractère spécial : 70 par SMS" : "");
    };
    t.addEventListener("input", maj);
    maj();
  });
  // Cartes d'offres : les prix suivent le métier choisi dans le même formulaire.
  document.querySelectorAll("fieldset[data-prix]").forEach(function (fs) {
    var prix = JSON.parse(fs.getAttribute("data-prix")), metier = fs.form && fs.form.querySelector("select[name=metier]");
    if (!metier) return;
    var maj = function () {
      var p = prix[metier.value] || {};
      fs.querySelectorAll(".offre-prix[data-formule]").forEach(function (s) {
        var v = p[s.getAttribute("data-formule")];
        if (v) s.textContent = String(v).replace(/\B(?=(\d{3})+(?!\d))/g, " ") + " €";
      });
    };
    metier.addEventListener("change", maj);
    maj();
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
