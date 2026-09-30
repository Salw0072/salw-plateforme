/* Plateforme SALW Santé · pages patients : assistant de la clinique. */
(function () {
  "use strict";
  var bloc = document.getElementById("assistant");
  if (!bloc) return;
  var bouton = document.getElementById("assistant-ouvrir"), fenetre = document.getElementById("assistant-fenetre");
  var fil = document.getElementById("assistant-fil"), form = document.getElementById("assistant-form"), saisie = document.getElementById("assistant-saisie");
  var historique = [];
  bouton.addEventListener("click", function () {
    fenetre.hidden = !fenetre.hidden;
    bouton.setAttribute("aria-expanded", fenetre.hidden ? "false" : "true");
    if (!fenetre.hidden) saisie.focus();
  });
  function bulle(texte, classe) {
    var p = document.createElement("p");
    p.className = classe;
    p.textContent = texte;
    fil.appendChild(p);
    fil.scrollTop = fil.scrollHeight;
    return p;
  }
  form.addEventListener("submit", function (e) {
    e.preventDefault();
    var t = saisie.value.trim();
    if (!t) return;
    saisie.value = "";
    bulle(t, "a-moi");
    historique.push({ role: "user", content: t });
    var attente = bulle("…", "a-sam");
    fetch("assistant.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ c: bloc.getAttribute("data-clinique"), messages: historique }) })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        attente.textContent = j && j.ok ? j.texte : "Je n'arrive pas à répondre pour le moment : appelez la clinique.";
        if (j && j.ok) historique.push({ role: "assistant", content: j.texte });
      })
      .catch(function () { attente.textContent = "Connexion impossible : réessayez ou appelez la clinique."; });
  });
})();
