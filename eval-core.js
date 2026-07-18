// eval-core.js — shared evaluation criteria & scoring
// Used by the applicant form (index.html, business self-assessment only)
// and by the admin dashboard (admin/index.html, full IT evaluation).

const BUSINESS_CRITERIA = [
  { id:"b1", title:"وضوح الهدف والمشكلة الحالية", weight:10, desc:"وضوح الحاجة التشغيلية والمشكلة الحالية والأثر المتوقع." },
  { id:"b2", title:"المواءمة مع الأهداف الاستراتيجية", weight:10, desc:"ارتباط الطلب بأهداف الهيئة والتحول الرقمي والحوكمة." },
  { id:"b3", title:"الأثر التشغيلي على أعمال الهيئة", weight:12, desc:"تحسين سير العمل وتقليل الجهد اليدوي وتسريع الإجراءات." },
  { id:"b4", title:"حجم المستفيدين ونطاق التأثير", weight:8, desc:"نطاق التأثير: إدارة واحدة، عدة إدارات، أو مستوى الهيئة." },
  { id:"b5", title:"درجة الإلحاح والاحتياج", weight:8, desc:"مدى الحاجة العاجلة ووجود أثر مباشر في حال التأخير." },
  { id:"b6", title:"جاهزية الإدارة الطالبة", weight:6, desc:"وجود مالك مشروع وفريق داعم واستعداد للتحليل والاختبار." },
  { id:"b7", title:"اكتمال آلية العمل والوثائق الداعمة", weight:6, desc:"توفر As-Is و To-Be والنماذج والتقارير والبيانات." }
];

const TECHNICAL_CRITERIA = [
  { id:"t1", title:"قابلية التطبيق الفنية", weight:8, desc:"إمكانية التنفيذ بالحلول التقنية المتاحة دون تعارض جوهري." },
  { id:"t2", title:"التوافق مع المعمارية التقنية", weight:6, desc:"التوافق مع الأنظمة والمنصات والبنية التقنية المعتمدة." },
  { id:"t3", title:"جاهزية البنية التحتية والموارد", weight:6, desc:"توفر الخوادم وقواعد البيانات والشبكات والتراخيص والموارد." },
  { id:"t4", title:"جاهزية البيانات والتكامل", weight:6, desc:"توفر البيانات ومصادرها وإمكانية التكامل مع الأنظمة الأخرى." },
  { id:"t5", title:"مستوى التعقيد والمدة المتوقعة", weight:5, desc:"تعقيد التحليل والتطوير والاختبار مقارنة بالأثر المتوقع." },
  { id:"t6", title:"مخاطر الأمن السيبراني وحماية البيانات", weight:5, desc:"المخاطر المتعلقة بالصلاحيات والبيانات الحساسة والامتثال." },
  { id:"t7", title:"الاستدامة وقابلية التشغيل والدعم", weight:4, desc:"سهولة الصيانة والدعم والتطوير المستقبلي وقياس الأداء." }
];

function scoreOptions(){
  return `
    <option value="0">اختر</option>
    <option value="5">5 - ممتاز / مكتمل</option>
    <option value="4">4 - جيد جداً</option>
    <option value="3">3 - متوسط</option>
    <option value="2">2 - ضعيف</option>
    <option value="1">1 - غير واضح</option>
  `;
}

// Render an editable criteria table body. If `values` is provided, preset the selects.
function renderCriteria(targetId, items, values){
  const target = document.getElementById(targetId);
  if(!target) return;
  target.innerHTML = items.map(item => `
    <tr>
      <td>
        <div class="criterion-title">${item.title}</div>
        <div class="criterion-desc">${item.desc}</div>
      </td>
      <td class="weight">${item.weight}%</td>
      <td>
        <select class="score-select" name="${item.id}" data-weight="${item.weight}" onchange="calculateAll()">
          ${scoreOptions()}
        </select>
      </td>
      <td class="weighted-score" id="${item.id}_weighted">0</td>
    </tr>
  `).join("");
  if(values){
    items.forEach(it => {
      const el = target.querySelector(`[name="${it.id}"]`);
      if(el && values[it.id] != null && values[it.id] !== "") el.value = String(values[it.id]);
    });
  }
}

function weightedValue(score, weight){
  return (Number(score || 0) / 5) * Number(weight || 0);
}

// Compute a group's weighted total from DOM selects, updating the per-row cells.
function calculateGroup(items){
  let total = 0;
  items.forEach(item => {
    const field = document.querySelector(`[name="${item.id}"]`);
    const value = weightedValue(field ? field.value : 0, item.weight);
    total += value;
    const cell = document.getElementById(`${item.id}_weighted`);
    if(cell) cell.textContent = value.toFixed(1);
  });
  return total;
}

// Compute a group's weighted total from a plain values object (no DOM).
function groupFromValues(items, values){
  let total = 0;
  items.forEach(item => { total += weightedValue(values ? values[item.id] : 0, item.weight); });
  return total;
}

function getApplicabilityText(value){
  const map = {
    applicable:"قابل للتطبيق",
    conditional:"قابل للتطبيق مع متطلبات إضافية",
    not_now:"غير قابل للتطبيق حالياً",
    not_applicable:"غير قابل للتطبيق"
  };
  return map[value] || "غير محدد";
}

function classifyPriority(total, applicability){
  if(applicability === "not_applicable"){
    return { label:"مرفوض", cls:"rejected", decision:"مرفوض",
      message:"الطلب غير قابل للتطبيق فنياً أو تنظيمياً، ويوصى برفضه أو إعادة تصميمه من الأساس." };
  }
  if(applicability === "not_now"){
    return { label:"مؤجل", cls:"low", decision:"مؤجل",
      message:"الطلب غير قابل للتطبيق حالياً بسبب نقص متطلبات أو موارد أو جاهزية، ويوصى بتأجيله مع تحديد المتطلبات الناقصة." };
  }
  if(total >= 85){
    return { label:"أولوية حرجة / استراتيجية", cls:"critical",
      decision: applicability === "conditional" ? "مقبول بشروط" : "مقبول للتنفيذ",
      message:"الطلب ذو أولوية عالية جداً، ويوصى بإدراجه في مسار سريع أو خطة تنفيذ قريبة." };
  }
  if(total >= 75){
    return { label:"أولوية عالية", cls:"high",
      decision: applicability === "conditional" ? "مقبول بشروط" : "مقبول للتنفيذ",
      message:"الطلب مهم وله أثر واضح، ويوصى بإدراجه ضمن خطة التنفيذ القريبة." };
  }
  if(total >= 60){
    return { label:"أولوية متوسطة", cls:"medium",
      decision: applicability === "conditional" ? "مقبول بشروط" : "يدرج في خطة المشاريع حسب الموارد",
      message:"الطلب مناسب للجدولة ضمن خطة المشاريع الدورية حسب توفر الموارد." };
  }
  if(total >= 45){
    return { label:"أولوية منخفضة", cls:"low", decision:"مؤجل",
      message:"الطلب تحسيني أو محدود الأثر، ويمكن تأجيله أو دمجه مع طلبات مشابهة." };
  }
  return { label:"غير مؤهل حالياً", cls:"rejected", decision:"يعاد للإدارة الطالبة للاستكمال",
    message:"الطلب يحتاج إلى استكمال المبررات أو الوثائق أو إعادة توضيح الأثر قبل اعتماده." };
}
