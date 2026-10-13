// Comprueba que los colores de la caja salen iguales en PHP (dox_pos_theme_vars, includes/settings.php)
// y en la vista previa de Ajustes > Marca (temaVars, assets/js/ajustes.js), con los de fábrica, los de
// las tiendas que usan Dox POS y unas paletas al azar. Uso: node tools/test-colors.js
const fs = require("fs"), path = require("path"), { execFileSync } = require("child_process");
const raiz = path.join(__dirname, "..");

// Las funciones de color de ajustes.js, sacadas del archivo tal cual.
const js = fs.readFileSync(path.join(raiz, "assets/js/ajustes.js"), "utf8");
const desde = js.indexOf("\tfunction hex(v)"), hasta = js.indexOf("\tconst swatches");
const temaVars = new Function(js.slice(desde, hasta) + "\nreturn temaVars;")();

const paletas = {
	fabrica: null,
	rosella: { bg: "#FEF8F4", bar: "#F4C1C0", primary: "#A17D83", soft: "#F4C1C0", ink: "#38352F" },
	cakto: { bg: "#FFF9F4", bar: "#BCCB03", primary: "#BCCC01", soft: "#004E20", ink: "#26221F" },
	anacleta: { bg: "#FFF9F4", bar: "#FAF6EB", primary: "#D6B699", soft: "#FFE8CC", ink: "#26221F" },
	shopnegro: { bg: "#FFF9F4", bar: "#26221F", primary: "#C1A30B", soft: "#281906", ink: "#26221F" },
};
const azar = () => "#" + Math.floor(Math.random() * 0xFFFFFF).toString(16).padStart(6, "0").toUpperCase();
for (let i = 0; i < 40; i++) paletas["azar" + i] = { bg: azar(), bar: azar(), primary: azar(), soft: azar(), ink: azar() };

// Las mismas funciones en PHP, sin cargar WordPress.
const php = `
$src = file_get_contents('${path.join(raiz, "includes/settings.php").replace(/'/g, "\\'")}');
foreach (['dox_pos_default_colors','dox_pos_mix','dox_pos_luminance','dox_pos_is_dark','dox_pos_contrast','dox_pos_readable','dox_pos_toward','dox_pos_theme_vars'] as $f) {
	preg_match('/function ' . $f . '\\(.*?\\n}\\n/s', $src, $m); eval($m[0]);
}
$p = json_decode(stream_get_contents(STDIN), true); $o = [];
foreach ($p as $k => $c) { $c = $c ?: dox_pos_default_colors(); $o[$k] = ['c' => $c, 'v' => dox_pos_theme_vars($c)]; }
echo json_encode($o);`;
const res = JSON.parse(execFileSync("php", ["-r", php], { input: JSON.stringify(paletas) }));

let mal = 0;
for (const [k, { c, v }] of Object.entries(res)) {
	const j = temaVars(c);
	for (const n of new Set([...Object.keys(v), ...Object.keys(j)])) {
		if (v[n] !== j[n]) { mal++; console.log(`${k} ${n}: PHP ${v[n]} · JS ${j[n]}`); }
	}
}
console.log(mal ? `${mal} diferencias` : `iguales en ${Object.keys(res).length} paletas`);
process.exit(mal ? 1 : 0);
