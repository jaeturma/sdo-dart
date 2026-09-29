import pptxgen from 'pptxgenjs';
import path from 'node:path';
import fs from 'node:fs';

const pptx = new pptxgen();
pptx.layout = 'LAYOUT_WIDE';
pptx.author = 'DepEd DART';
pptx.subject = 'DART adoption presentation';
pptx.title = 'DepEd DART: A practical document control system for Schools Division offices';
pptx.company = 'DepEd';
pptx.lang = 'en-PH';
pptx.theme = {
  headFontFace: 'Aptos Display',
  bodyFontFace: 'Aptos',
  lang: 'en-PH'
};
pptx.defineSlideMaster({
  title: 'MASTER',
  background: { color: 'F7F9FC' },
  objects: [
    { rect: { x: 0, y: 7.18, w: 13.333, h: 0.32, fill: { color: '003087' }, line: { color: '003087' } } },
    { text: { text: 'DEPED DART  |  DOCUMENT ADMINISTRATION AND ROUTING TOOL', options: { x: 0.45, y: 7.22, w: 7.5, h: 0.12, fontFace: 'Aptos', fontSize: 6.5, color: 'FFFFFF', bold: true, margin: 0 } } },
    { text: { text: 'Adoption briefing', options: { x: 10.7, y: 7.22, w: 2.15, h: 0.12, fontFace: 'Aptos', fontSize: 6.5, color: 'FFFFFF', align: 'right', margin: 0 } } }
  ],
  slideNumber: { x: 12.95, y: 7.21, color: 'FFFFFF', fontFace: 'Aptos', fontSize: 6.5 }
});

const root = process.cwd();
const logo = path.join(root, 'public', 'images', 'dart-logo.png');
const seal = path.join(root, 'public', 'images', 'deped-xi-seal.png');
const out = path.join(root, 'docs', 'DART_Adoption_Presentation.pptx');
fs.mkdirSync(path.dirname(out), { recursive: true });

const C = {
  navy: '003087',
  blue: '1261A0',
  sky: 'EAF3FB',
  gold: 'F5C542',
  orange: 'E67E22',
  green: '198754',
  red: 'B42318',
  ink: '1C2733',
  muted: '5C6B7A',
  line: 'D8E0E8',
  white: 'FFFFFF',
  pale: 'F7F9FC',
  teal: '087F8C'
};

function addText(slide, text, x, y, w, h, opts = {}) {
  slide.addText(text, {
    x, y, w, h, fontFace: opts.fontFace || 'Aptos', fontSize: opts.fontSize || 16,
    color: opts.color || C.ink, bold: opts.bold || false, italic: opts.italic || false,
    align: opts.align || 'left', valign: opts.valign || 'mid', margin: opts.margin ?? 0,
    breakLine: false, fit: 'shrink', bullet: opts.bullet, paraSpaceAfterPt: opts.paraSpaceAfterPt,
    charSpacing: opts.charSpacing || 0, transparency: opts.transparency
  });
}
function box(slide, x, y, w, h, fill, radius = 0.08, line = fill) {
  slide.addShape(pptx.ShapeType.roundRect, { x, y, w, h, rectRadius: radius, fill: { color: fill }, line: { color: line, width: 0.8 } });
}
function line(slide, x1, y1, x2, y2, color = C.line, width = 1.2, end = 'none') {
  slide.addShape(pptx.ShapeType.line, { x: x1, y: y1, w: x2 - x1, h: y2 - y1, line: { color, width, endArrowType: end } });
}
function title(slide, kicker, heading, sub = '') {
  addText(slide, kicker.toUpperCase(), 0.55, 0.38, 3.2, 0.22, { fontSize: 9, color: C.blue, bold: true, charSpacing: 1.5 });
  addText(slide, heading, 0.55, 0.66, 12.1, 0.56, { fontSize: 28, color: C.navy, bold: true });
  if (sub) addText(slide, sub, 0.58, 1.28, 11.4, 0.3, { fontSize: 12, color: C.muted });
}
function pill(slide, text, x, y, w, fill, color = C.white) {
  box(slide, x, y, w, 0.32, fill, 0.16, fill);
  addText(slide, text, x, y + 0.01, w, 0.27, { fontSize: 9, color, bold: true, align: 'center' });
}
function iconCircle(slide, label, x, y, fill, color = C.white) {
  slide.addShape(pptx.ShapeType.ellipse, { x, y, w: 0.52, h: 0.52, fill: { color: fill }, line: { color: fill } });
  addText(slide, label, x, y + 0.01, 0.52, 0.46, { fontSize: 15, color, bold: true, align: 'center' });
}
function note(slide, text) { if (typeof slide.addNotes === 'function') slide.addNotes(text); }
function bulletList(slide, items, x, y, w, h, opts = {}) {
  items.forEach((item, i) => {
    const yy = y + i * (opts.gap || 0.48);
    iconCircle(slide, opts.icon || '•', x, yy + 0.03, opts.fill || C.blue, opts.iconColor || C.white);
    addText(slide, item, x + 0.68, yy, w - 0.68, opts.rowH || 0.36, { fontSize: opts.fontSize || 14, color: opts.color || C.ink, bold: opts.bold || false });
  });
}

// 1
{
  const s = pptx.addSlide();
  s.background = { color: C.navy };
  s.addShape(pptx.ShapeType.rect, { x: 0, y: 0, w: 13.333, h: 7.5, fill: { color: C.navy }, line: { color: C.navy } });
  s.addShape(pptx.ShapeType.arc, { x: 8.0, y: -1.3, w: 6.5, h: 6.5, line: { color: C.gold, width: 5, transparency: 18 }, adjustPoint: 0.25 });
  s.addShape(pptx.ShapeType.arc, { x: 9.0, y: 1.8, w: 5.3, h: 5.3, line: { color: '4EA1D3', width: 2, transparency: 20 }, adjustPoint: 0.25 });
  if (fs.existsSync(logo)) s.addImage({ path: logo, x: 0.78, y: 0.72, w: 1.42, h: 1.42 });
  if (fs.existsSync(seal)) s.addImage({ path: seal, x: 11.72, y: 0.66, w: 0.9, h: 0.9 });
  addText(s, 'DEPED DART', 0.85, 2.55, 7.3, 0.7, { fontSize: 34, color: C.white, bold: true });
  addText(s, 'A practical document control system\nfor Schools Division offices', 0.87, 3.28, 7.4, 1.0, { fontSize: 25, color: C.white, bold: true });
  addText(s, 'Adoption briefing | How the system works, what an office needs, and how to start', 0.9, 4.55, 7.8, 0.5, { fontSize: 14, color: 'DCEBFA' });
  pill(s, 'SUBMIT', 0.9, 5.62, 1.15, C.blue);
  pill(s, 'REVIEW', 2.2, 5.62, 1.15, C.teal);
  pill(s, 'ROUTE', 3.5, 5.62, 1.15, C.orange);
  pill(s, 'SIGN', 4.8, 5.62, 1.15, C.green);
  addText(s, 'Prepared for Schools Division personnel', 0.9, 6.55, 5.2, 0.25, { fontSize: 10, color: 'BBD1E8' });
  addText(s, 'DART = Document Administration and Routing Tool', 8.0, 5.9, 4.5, 0.35, { fontSize: 13, color: C.gold, bold: true, align: 'right' });
  note(s, 'Opening: DART is not just an electronic inbox. It is a controlled workflow for documents produced by the office and documents received by the office. Today we will see the user experience and the practical path for an SDO to operate it with its own resources.');
}

// 2
{
  const s = pptx.addSlide('MASTER');
  title(s, 'The proposition', 'Move documents from “where is it?” to “who owns the next action?”', 'DART makes responsibility, status, and evidence visible at every step.');
  const cols = [
    ['BEFORE', 'Paper folders, chat follow-ups, manual routing slips, and uncertain status.', C.red, 'Questions arrive before the document.'],
    ['WITH DART', 'Role-scoped queues, clear workflow stages, notifications, and searchable history.', C.blue, 'The next responsible person is visible.'],
    ['OUTCOME', 'Faster handoffs, fewer lost documents, stronger accountability, and an auditable record.', C.green, 'The office can prove what happened.']
  ];
  cols.forEach((c, i) => {
    const x = 0.65 + i * 4.2;
    box(s, x, 2.05, 3.72, 3.35, C.white, 0.1, C.line);
    pill(s, c[0], x + 0.28, 2.33, 1.18, c[2]);
    addText(s, c[1], x + 0.3, 2.88, 3.1, 1.05, { fontSize: 18, color: C.navy, bold: true });
    line(s, x + 0.3, 4.2, x + 3.35, 4.2, C.line, 1);
    addText(s, c[3], x + 0.3, 4.45, 3.0, 0.5, { fontSize: 13, color: C.muted });
  });
  addText(s, 'The adoption question is operational: can the office agree on roles, routing rules, and ownership?', 0.72, 6.12, 11.6, 0.4, { fontSize: 17, color: C.navy, bold: true, align: 'center' });
  note(s, 'Frame the value in office language: fewer follow-up calls, less time looking for papers, and a defensible transaction history. Avoid presenting DART as technology for its own sake.');
}

// 3
{
  const s = pptx.addSlide('MASTER');
  title(s, 'The operating model', 'One system, two document journeys', 'Outgoing documents need decisions. Incoming documents need dispatch and completion.');
  box(s, 0.72, 2.0, 5.72, 3.65, C.sky, 0.12, 'B9D8EF');
  box(s, 6.88, 2.0, 5.72, 3.65, 'FFF5E5', 0.12, 'F1D18B');
  addText(s, 'OUTGOING', 1.05, 2.35, 2.3, 0.35, { fontSize: 22, color: C.navy, bold: true });
  addText(s, 'Produced by the office\nand sent upward for approval', 1.05, 2.78, 3.7, 0.6, { fontSize: 14, color: C.muted });
  const out = ['Draft', 'Review chain', 'RD approval', 'Digital signature', 'Release / email'];
  out.forEach((t, i) => { const x = 1.0 + i * 0.98; iconCircle(s, String(i + 1), x, 4.2, i === 2 ? C.gold : C.blue, i === 2 ? C.navy : C.white); addText(s, t, x - 0.2, 4.85, 1.35, 0.45, { fontSize: 10, color: C.ink, align: 'center' }); if (i < out.length - 1) line(s, x + 0.53, 4.46, x + 0.9, 4.46, C.blue, 1.5, 'triangle'); });
  addText(s, 'The system answers: who reviews next?', 1.05, 5.35, 4.4, 0.25, { fontSize: 12, color: C.blue, bold: true });
  addText(s, 'INCOMING', 7.2, 2.35, 2.3, 0.35, { fontSize: 22, color: '8A4B00', bold: true });
  addText(s, 'Received from outside\nand routed to the right action owner', 7.2, 2.78, 3.7, 0.6, { fontSize: 14, color: C.muted });
  const inc = ['Receive', 'Dispatch', 'Acknowledge', 'Complete', 'Archive'];
  inc.forEach((t, i) => { const x = 7.15 + i * 0.98; iconCircle(s, String(i + 1), x, 4.2, i === 1 ? C.orange : 'C47A14'); addText(s, t, x - 0.2, 4.85, 1.35, 0.45, { fontSize: 10, color: C.ink, align: 'center' }); if (i < inc.length - 1) line(s, x + 0.53, 4.46, x + 0.9, 4.46, 'C47A14', 1.5, 'triangle'); });
  addText(s, 'The system answers: who owns the action?', 7.2, 5.35, 4.4, 0.25, { fontSize: 12, color: 'A95F00', bold: true });
  note(s, 'This is the core mental model. Keep coming back to it during the walkthrough: outgoing is approval; incoming is routing and action.');
}

// 4
{
  const s = pptx.addSlide('MASTER');
  title(s, 'Outgoing walkthrough', 'From draft to released document', 'The route is automatically shaped by the submitter’s role and division structure.');
  const stages = [
    ['1', 'Submit', 'Originator uploads a genuine PDF and chooses whether release is required.', C.blue],
    ['2', 'Review', 'Unit Head / Division Chief / ARD / RD act in sequence where applicable.', C.teal],
    ['3', 'Decide', 'For comment, return for revision, reject, recommend, or approve.', C.orange],
    ['4', 'Sign', 'Secretary applies the RD’s PKI credential to the approved PDF.', C.green],
    ['5', 'Release', 'Records Section applies its release stamp and confirms email dispatch.', C.navy]
  ];
  stages.forEach((st, i) => {
    const x = 0.62 + i * 2.52;
    iconCircle(s, st[0], x + 0.73, 2.0, st[3]);
    addText(s, st[1], x, 2.72, 2.0, 0.3, { fontSize: 16, color: C.navy, bold: true, align: 'center' });
    addText(s, st[2], x + 0.08, 3.18, 2.28, 1.2, { fontSize: 12, color: C.muted, align: 'center' });
    if (i < stages.length - 1) line(s, x + 1.92, 2.26, x + 2.48, 2.26, C.line, 1.7, 'triangle');
  });
  box(s, 1.05, 5.15, 11.15, 0.9, C.white, 0.08, C.line);
  addText(s, 'Every decision creates a durable review-history entry: who acted, in which role, when, and with what remarks.', 1.35, 5.42, 10.55, 0.35, { fontSize: 16, color: C.navy, bold: true, align: 'center' });
  note(s, 'Walk through one familiar example such as a memorandum or endorsement. Emphasize that the chain is not hard-coded to six steps; it adapts to the office hierarchy and the user submitting the document.');
}

// 5
{
  const s = pptx.addSlide('MASTER');
  title(s, 'Incoming walkthrough', 'Route the document, then route the work', 'A routing entry can target a person or an entire division.');
  const rows = [
    ['Received', 'Secretary uploads the incoming PDF with source and reference details.', C.blue],
    ['Dispatch', 'RD, ARD, RD secretary, or an eligible chief selects recipients and action.', C.orange],
    ['Acknowledge', 'The recipient confirms ownership and sees the requested action.', C.teal],
    ['Complete', 'The recipient records the response or completion note.', C.green],
    ['History', 'The document retains the routing round, recipient, timestamps, and status.', C.navy]
  ];
  rows.forEach((r, i) => {
    const y = 1.93 + i * 0.82;
    iconCircle(s, String(i + 1), 0.85, y + 0.05, r[2]);
    addText(s, r[0], 1.62, y, 1.6, 0.32, { fontSize: 16, color: C.navy, bold: true });
    addText(s, r[1], 3.25, y, 8.8, 0.4, { fontSize: 14, color: C.ink });
    if (i < rows.length - 1) line(s, 1.11, y + 0.58, 1.11, y + 0.86, C.line, 1.4, 'triangle');
  });
  box(s, 9.75, 5.98, 2.65, 0.52, 'FFF5E5', 0.08, 'F1D18B');
  addText(s, 'RUSH documents stay visible.', 9.88, 6.12, 2.38, 0.2, { fontSize: 10, color: '8A4B00', bold: true, align: 'center' });
  note(s, 'Point out the distinction between receiving a document and dispatching it for action. Records Section can receive an automatic courtesy copy for ORD Proper uploads, while the RD or her delegate still controls the real dispatch decision.');
}

// 6
{
  const s = pptx.addSlide('MASTER');
  title(s, 'Roles and accountability', 'DART follows the office structure; it does not flatten it', 'Each role sees the queue and actions needed for that role.');
  const roles = [
    ['Originator', 'Creates outgoing documents and responds to comments.', C.blue],
    ['Unit Head / Chief', 'Reviews upward; chiefs can route work within their division.', C.teal],
    ['ARD / RD', 'Recommends or approves; RD controls top-level incoming dispatch.', C.orange],
    ['Secretary', 'Uploads incoming mail; signs approved outgoing documents for the RD.', C.green],
    ['Records Section', 'Applies release stamp; receives records copies and closes the loop.', C.navy],
    ['Admin', 'Creates users, assigns divisions, controls settings, and monitors credentials.', '6C5CE7']
  ];
  roles.forEach((r, i) => {
    const col = i % 3; const row = Math.floor(i / 3); const x = 0.68 + col * 4.2; const y = 1.95 + row * 2.1;
    box(s, x, y, 3.72, 1.58, C.white, 0.08, C.line);
    iconCircle(s, r[0].slice(0, 1), x + 0.25, y + 0.27, r[2]);
    addText(s, r[0], x + 0.95, y + 0.28, 2.48, 0.3, { fontSize: 16, color: C.navy, bold: true });
    addText(s, r[1], x + 0.27, y + 0.84, 3.1, 0.48, { fontSize: 12, color: C.muted });
  });
  note(s, 'Adoption succeeds when the division’s real accountability structure is represented accurately. The admin configures the structure once; users then operate in role-scoped queues.');
}

// 7
{
  const s = pptx.addSlide('MASTER');
  title(s, 'Trust by design', 'Security and evidence are part of the workflow', 'The system protects both the document and the decision around it.');
  const security = [
    ['Identity', 'Mandatory two-factor authentication, password controls, and optional email verification.', C.blue],
    ['Authorization', 'Policies control access; the interface only reflects what the server permits.', C.teal],
    ['Document integrity', 'Private file storage, PDF content validation, hashes, and tamper checks.', C.orange],
    ['Digital signature', 'PKI-backed certificate signing; the secretary attaches the RD’s credential.', C.green],
    ['Audit trail', 'Append-only review and audit records capture actions, actors, and timestamps.', C.navy],
    ['Continuity', 'Backups, credential renewal, and documented recovery procedures remain office responsibilities.', '6C5CE7']
  ];
  security.forEach((r, i) => {
    const col = i % 2; const row = Math.floor(i / 2); const x = 0.75 + col * 6.2; const y = 1.9 + row * 1.47;
    iconCircle(s, String(i + 1), x, y + 0.08, r[2]);
    addText(s, r[0], x + 0.78, y, 2.3, 0.3, { fontSize: 16, color: C.navy, bold: true });
    addText(s, r[1], x + 0.78, y + 0.4, 4.75, 0.48, { fontSize: 12, color: C.muted });
  });
  note(s, 'Be precise: DART provides strong application controls, but no application replaces office security discipline. The adopting office owns passwords, devices, network access, backups, certificate custody, and incident response.');
}

// 8
{
  const s = pptx.addSlide('MASTER');
  title(s, 'Self-hosted deployment', 'The office can run DART using its own infrastructure', 'Recommended production shape: a small Linux server, private storage, database, HTTPS, and routine backups.');
  // architecture
  box(s, 0.68, 2.0, 2.5, 3.32, C.white, 0.08, C.line);
  addText(s, 'OFFICE USERS', 1.0, 2.35, 1.85, 0.3, { fontSize: 16, color: C.navy, bold: true, align: 'center' });
  ['Browser', 'Office LAN / VPN', '2FA device'].forEach((t, i) => { pill(s, t, 1.0, 3.0 + i * 0.58, 1.85, i === 1 ? C.teal : C.blue); });
  box(s, 4.1, 2.0, 3.2, 3.32, C.sky, 0.08, 'B9D8EF');
  addText(s, 'APPLICATION SERVER', 4.45, 2.35, 2.5, 0.3, { fontSize: 16, color: C.navy, bold: true, align: 'center' });
  ['PHP + Laravel', 'Vue + Inertia', 'HTTPS web server', 'Queue / scheduler'].forEach((t, i) => { pill(s, t, 4.72, 2.95 + i * 0.49, 1.95, C.navy); });
  box(s, 8.3, 2.0, 2.0, 3.32, 'FFF5E5', 0.08, 'F1D18B');
  addText(s, 'DATA', 8.85, 2.35, 0.9, 0.3, { fontSize: 16, color: '8A4B00', bold: true, align: 'center' });
  ['MySQL', 'Private files', 'Credentials'].forEach((t, i) => { pill(s, t, 8.62, 3.0 + i * 0.58, 1.35, C.orange); });
  box(s, 11.0, 2.0, 1.55, 3.32, 'EAF7EE', 0.08, 'B9E0C4');
  addText(s, 'OPERATIONS', 11.17, 2.35, 1.2, 0.3, { fontSize: 13, color: C.green, bold: true, align: 'center' });
  ['Backups', 'Updates', 'Monitoring'].forEach((t, i) => { addText(s, t, 11.16, 3.08 + i * 0.59, 1.2, 0.25, { fontSize: 11, color: C.ink, align: 'center' }); });
  line(s, 3.22, 3.55, 4.08, 3.55, C.blue, 2, 'triangle');
  line(s, 7.32, 3.55, 8.28, 3.55, C.blue, 2, 'triangle');
  line(s, 10.32, 3.55, 10.98, 3.55, C.blue, 2, 'triangle');
  addText(s, 'A server is only part of deployment. The office also needs an owner, a support process, a backup policy, and a rollout plan.', 1.05, 5.85, 11.2, 0.48, { fontSize: 16, color: C.navy, bold: true, align: 'center' });
  note(s, 'The deck is not prescribing one vendor. The key is separation: users connect over HTTPS; the application talks to the database and private storage; operations keeps it patched and recoverable. A cloud VM or an office server can both fit this shape.');
}

// 9
{
  const s = pptx.addSlide('MASTER');
  title(s, 'What the adopting office supplies', 'Minimum resource checklist', 'Start small, but assign ownership before the first live document.');
  const items = [
    ['People', 'Executive sponsor, system administrator, records/process owner, and departmental champions.'],
    ['Server', 'Production host with PHP/Laravel, MySQL, private file storage, HTTPS, and room for backups.'],
    ['Network', 'Stable office LAN or VPN access; DNS or a documented internal URL; firewall rules.'],
    ['Credentials', 'RD signing certificate, Records Section certificate, 2FA devices, and renewal calendar.'],
    ['Policy', 'Approved role matrix, routing rules, retention schedule, backup schedule, and incident contacts.'],
    ['Change effort', 'Training, migration decisions, pilot documents, and a fallback process during transition.']
  ];
  items.forEach((r, i) => {
    const y = 1.88 + i * 0.73;
    iconCircle(s, String(i + 1), 0.78, y + 0.04, i < 3 ? C.blue : C.teal);
    addText(s, r[0], 1.55, y, 1.65, 0.3, { fontSize: 15, color: C.navy, bold: true });
    addText(s, r[1], 3.15, y, 8.9, 0.36, { fontSize: 13, color: C.ink });
    line(s, 1.55, y + 0.54, 12.0, y + 0.54, C.line, 0.7);
  });
  note(s, 'This slide is a useful handoff: technology staff can own the server, but business owners must own the workflow. The office should name both before procurement or deployment begins.');
}

// 10
{
  const s = pptx.addSlide('MASTER');
  title(s, 'Implementation playbook', 'A controlled 30-60-90 day adoption path', 'Pilot the operating model before expanding it across every unit.');
  const phases = [
    ['DAYS 0-30', 'Prepare', ['Name sponsor and system owner', 'Map divisions, roles, and routing rules', 'Provision server, HTTPS, database, and backups', 'Prepare test users and sample documents'], C.blue],
    ['DAYS 31-60', 'Pilot', ['Choose 1-2 divisions plus Records', 'Run outgoing and incoming scenarios', 'Test 2FA, signing, restore, and audit review', 'Collect issues and adjust SOPs'], C.orange],
    ['DAYS 61-90', 'Scale', ['Onboard remaining divisions in waves', 'Retire duplicate manual trackers', 'Publish escalation and support contacts', 'Review metrics and approve steady-state ownership'], C.green]
  ];
  phases.forEach((p, i) => {
    const x = 0.7 + i * 4.2;
    box(s, x, 1.92, 3.7, 4.35, C.white, 0.08, C.line);
    pill(s, p[0], x + 0.28, 2.2, 1.28, p[3]);
    addText(s, p[1], x + 0.28, 2.75, 3.0, 0.35, { fontSize: 22, color: C.navy, bold: true });
    p[2].forEach((t, j) => { iconCircle(s, '✓', x + 0.28, 3.42 + j * 0.62, p[3]); addText(s, t, x + 0.94, 3.42 + j * 0.62, 2.35, 0.35, { fontSize: 12, color: C.ink }); });
  });
  note(s, 'Do not make the first day a whole-division migration. A pilot exposes unclear authority, missing divisions, certificate issues, and backup gaps while the blast radius is small.');
}

// 11
{
  const s = pptx.addSlide('MASTER');
  title(s, 'Training plan', 'Teach the workflow by role, not by menu item', 'People learn faster when the training follows the work they are accountable for.');
  const tracks = [
    ['All users', 'Login, 2FA, dashboard, search, notifications, document preview, and safe handling.', C.blue],
    ['Originators', 'Create outgoing documents, choose release requirement, read remarks, resubmit corrections.', C.teal],
    ['Reviewers', 'Review chain, remarks, decision options, scope of authority, and escalation.', C.orange],
    ['Secretary / Records', 'Incoming upload, dispatch, signing, release stamp, emailed confirmation, credential care.', C.green],
    ['Administrators', 'Users, divisions, settings, credentials, backups, access review, and support.', C.navy]
  ];
  tracks.forEach((r, i) => {
    const y = 1.9 + i * 0.86;
    pill(s, r[0], 0.78, y + 0.04, 1.75, r[2]);
    addText(s, r[1], 2.88, y, 9.4, 0.42, { fontSize: 15, color: C.ink });
    line(s, 0.78, y + 0.62, 12.1, y + 0.62, C.line, 0.7);
  });
  box(s, 1.0, 6.2, 11.2, 0.46, C.sky, 0.08, 'B9D8EF');
  addText(s, 'Practice with real office scenarios, but use sanitized sample documents during training.', 1.15, 6.33, 10.9, 0.18, { fontSize: 12, color: C.navy, bold: true, align: 'center' });
  note(s, 'A single generic demo will not be enough. The originator needs a different lesson from the Records Section clerk, and the admin needs a different lesson from the RD.');
}

// 12
{
  const s = pptx.addSlide('MASTER');
  title(s, 'Governance', 'Rules the office should decide before going live', 'Technology can enforce a rule only after the office agrees what the rule is.');
  const rules = [
    'Who may upload incoming documents, and who may dispatch them?',
    'Which divisions have unit heads and chiefs, and what are the parent-child relationships?',
    'When is a document for comment, returned for revision, or rejected?',
    'Who owns the RD credential and the Records Section release credential?',
    'What is the retention, backup, restore, and incident-response procedure?',
    'What counts as an urgent document, and how is escalation handled after hours?'
  ];
  rules.forEach((t, i) => {
    const col = i % 2; const row = Math.floor(i / 2); const x = 0.85 + col * 6.05; const y = 1.95 + row * 1.3;
    box(s, x, y, 5.5, 0.92, C.white, 0.08, C.line);
    iconCircle(s, '?', x + 0.22, y + 0.2, i < 3 ? C.orange : C.blue);
    addText(s, t, x + 0.95, y + 0.17, 4.2, 0.5, { fontSize: 13, color: C.ink, bold: true });
  });
  note(s, 'Use this slide to invite decisions from the audience. These are not merely IT questions. They are office governance questions that determine whether the system reflects reality.');
}

// 13
{
  const s = pptx.addSlide('MASTER');
  title(s, 'Success measures', 'What to monitor after adoption', 'Measure flow and accountability, not just logins.');
  const metrics = [
    ['Cycle time', 'Median time from submission to decision; time from receipt to completed action.', C.blue],
    ['Queue health', 'Documents waiting by role, overdue assignments, and aging by stage.', C.orange],
    ['Quality', 'Return-for-revision rate, rejected submissions, incomplete metadata, and duplicate routing.', C.teal],
    ['Control', 'Backup success, restore test results, 2FA coverage, certificate validity, and access reviews.', C.green]
  ];
  metrics.forEach((m, i) => {
    const x = 0.8 + (i % 2) * 6.08; const y = 1.98 + Math.floor(i / 2) * 2.1;
    box(s, x, y, 5.55, 1.48, C.white, 0.08, C.line);
    iconCircle(s, String(i + 1), x + 0.3, y + 0.35, m[2]);
    addText(s, m[0], x + 1.02, y + 0.28, 3.8, 0.3, { fontSize: 17, color: C.navy, bold: true });
    addText(s, m[1], x + 1.02, y + 0.72, 4.1, 0.46, { fontSize: 12, color: C.muted });
  });
  addText(s, 'The first review should happen after 30 days of pilot use, then monthly during scale-up.', 1.0, 6.35, 11.2, 0.28, { fontSize: 15, color: C.navy, bold: true, align: 'center' });
  note(s, 'The system can expose bottlenecks, but leaders must use that visibility. A queue that is always waiting on one role is an operating issue worth addressing, not just a software statistic.');
}

// 14
{
  const s = pptx.addSlide();
  s.background = { color: C.navy };
  if (fs.existsSync(logo)) s.addImage({ path: logo, x: 0.8, y: 0.78, w: 1.28, h: 1.28 });
  if (fs.existsSync(seal)) s.addImage({ path: seal, x: 11.85, y: 0.76, w: 0.78, h: 0.78 });
  addText(s, 'A practical next step', 0.85, 2.25, 8.3, 0.5, { fontSize: 30, color: C.gold, bold: true });
  addText(s, 'Adopt DART through a\nsmall, accountable pilot.', 0.85, 2.92, 8.6, 1.1, { fontSize: 30, color: C.white, bold: true });
  const asks = ['Name the executive sponsor and system owner', 'Select pilot divisions and Records Section', 'Agree on the role matrix and routing rules', 'Schedule technical provisioning and user training'];
  asks.forEach((t, i) => { iconCircle(s, '✓', 0.95, 4.55 + i * 0.48, C.green); addText(s, t, 1.68, 4.55 + i * 0.48, 7.2, 0.3, { fontSize: 14, color: C.white }); });
  addText(s, 'DART turns document movement into a visible, accountable office process.', 8.65, 5.15, 3.5, 0.8, { fontSize: 18, color: C.white, bold: true, align: 'right' });
  addText(s, 'Thank you', 8.65, 6.25, 3.5, 0.35, { fontSize: 22, color: C.gold, bold: true, align: 'right' });
  note(s, 'Close with a decision, not a generic thank-you: ask the audience to identify a sponsor, pilot group, and technical owner. That is enough to move from interest to a credible adoption conversation.');
}

await pptx.writeFile({ fileName: out });
console.log(`Wrote ${out}`);
