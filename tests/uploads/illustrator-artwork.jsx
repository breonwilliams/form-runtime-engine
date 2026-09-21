app.userInteractionLevel = UserInteractionLevel.DONTDISPLAYALERTS;
var out = "__OUT__";
var d = app.documents.add(DocumentColorSpace.CMYK, 600, 400);
var L = d.layers[0];
var r = L.pathItems.rectangle(380, 20, 560, 360); var c1 = new CMYKColor(); c1.cyan=80; c1.magenta=10; c1.yellow=0; c1.black=10; r.fillColor = c1; r.stroked=false;
var e = L.pathItems.ellipse(330, 60, 200, 200); var c2 = new CMYKColor(); c2.cyan=0; c2.magenta=90; c2.yellow=80; c2.black=0; e.fillColor = c2;
var star = L.pathItems.star(200, 420, 90, 40, 5); var c3 = new CMYKColor(); c3.yellow=100; star.fillColor = c3;
var t = L.textFrames.add(); t.contents = "725 PRINT TEST"; t.top = 120; t.left = 60; t.textRange.characterAttributes.size = 48;
var p = L.placedItems.add(); p.file = new File("/tmp/art/logo-mark.png"); p.top = 360; p.left = 330; p.embed();
function save(name, opts){ d.saveAs(new File(out+name), opts); }
var a = new IllustratorSaveOptions(); a.pdfCompatible = true; save("artwork-illustrator.ai", a);
var e1 = new EPSSaveOptions(); e1.preview = EPSPreview.COLORTIFF; e1.embedAllFonts = true; save("artwork-illustrator-preview.eps", e1);
var e2 = new EPSSaveOptions(); e2.preview = EPSPreview.None; save("artwork-illustrator-no-preview.eps", e2);
var pdf = new PDFSaveOptions(); save("artwork-illustrator.pdf", pdf);
var o = new ExportOptionsSVG(); o.preserveEditability = true; o.embedRasterImages = true; o.DTD = SVGDTDVersion.SVG1_1; d.exportFile(new File(out+"artwork-illustrator-editable.svg"), ExportType.SVG, o);
var o2 = new ExportOptionsSVG(); o2.preserveEditability = false; o2.embedRasterImages = true; o2.fontType = SVGFontType.OUTLINEFONT; d.exportFile(new File(out+"artwork-illustrator-plain.svg"), ExportType.SVG, o2);
d.close(SaveOptions.DONOTSAVECHANGES);
"ok";
