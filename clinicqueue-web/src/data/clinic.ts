/* ------------------------------------------------------------------
   Data ported 1:1 from the SwiftUI sources in
   clinic-queue-management-system-main/ClinicQueue/Core/Data
   ------------------------------------------------------------------ */

export const COLORS = {
  primary: '#169A8F',
  primaryDark: '#157979',
  secondary: '#AABBCC',
  text: '#737373',
  error: '#FF3B30',
  placeholder: '#A5A5A5',
  border: '#E8EBED',
  lable: '#C1C0C0',
  cardBg: '#F2F9F7',
  pink: '#FE8382',
  textDark: '#1E1E1E',
  navy: '#1A2E44',
  mint: '#0DC8A4',
  mapBg: '#0A1628',
  themeTeal: '#479484',
};

export type ServiceType = 'clinic' | 'lab' | 'imaging' | 'pharmacy' | 'appointment' | 'unknown';
export type ActivityStage = 'unknown' | 'planning' | 'inQueue' | 'completed' | 'canceled' | 'refund';
export type QueueStage = 'unknown' | 'wait' | 'next' | 'ready' | 'inProgress' | 'completed' | 'cancel';
export type StepStatus = 'pending' | 'waiting' | 'next' | 'ready' | 'inProgress' | 'completed';

export interface DoctorAvailability { id: string; weekday: string; timeRange: string; queueLabel: string; date: Date }

export interface Doctor {
  id: string;
  image: string;
  heading: string;
  subheading: string;
  activeQueueCount: string;
  detail1: { label: string; value: string };
  detail2: { label: string; value: string };
  price: string;
  availableDates: DoctorAvailability[];
  maxPatientsPerDay: number;
  specialty: string;
}

export interface ClinicStep {
  id: string;
  type: 'doctor' | 'labTest' | 'imaging';
  name: string;
  description?: string;
  estimatedWait?: string;
  price?: number;
  location?: string;
  requirements?: string[];
  status: StepStatus;
}

export interface Activity {
  id: string;
  service: ServiceType;
  stage: ActivityStage;
  selectedDoctor?: Doctor;
  queueNumber?: number;
  isSelected: boolean;
  queueStage: QueueStage;
  labStep?: ClinicStep;
  imagingStep?: ClinicStep;
  testName?: string;
  appointmentDate?: Date;
  patientName?: string;
  patientAge?: number;
  patientGender?: string;
  symptoms: string[];
}

export const uid = () =>
  'id-' + Math.random().toString(36).slice(2, 10) + '-' + Date.now().toString(36);

const clock = (prefix: string) => `${prefix}-${Math.random().toString(36).slice(2, 8)}`;

export function generateDates(days: number): DoctorAvailability[] {
  const out: DoctorAvailability[] = [];
  const weekdayFmt = new Intl.DateTimeFormat('en-US', { weekday: 'long' });
  const today = new Date();
  for (let i = 0; i < days; i++) {
    const d = new Date(today);
    d.setDate(today.getDate() + i);
    const weekday = i === 0 ? 'Today' : weekdayFmt.format(d);
    out.push({
      id: clock('date'),
      weekday,
      timeRange: '17:30 pm - 20:30 pm',
      queueLabel: String(i + 1),
      date: d,
    });
  }
  return out;
}

const createDoc = (
  id: string, name: string, sub: string, queue: string,
  wait: string, room: string, price: string, specialty: string
): Doctor => ({
  id: `doc-${id}`,
  image: `/assets/doctors/doctor${id}.png`,
  heading: name,
  subheading: sub,
  activeQueueCount: `${queue} patients in queue`,
  detail1: { label: 'Estimated wait:', value: `~${wait} min` },
  detail2: { label: 'Location:', value: `Room ${room}` },
  price: `$${price.replace('$', '')}`,
  availableDates: generateDates(7),
  maxPatientsPerDay: 20,
  specialty,
});

export interface DoctorGroup { specialty: string; doctors: Doctor[] }

export const doctorGroups: DoctorGroup[] = [
  { specialty: 'General', doctors: [
    createDoc('08', 'Dr. Sam Wilson', 'General Practitioner', '15', '15', '01', '20', 'General'),
    createDoc('21', 'Dr. Alicia Keys', 'Family Medicine', '4', '10', '03', '20', 'General'),
  ]},
  { specialty: 'Cardiology', doctors: [
    createDoc('01', 'Dr. Jane Doe', 'Cardiologist', '12', '25', '02', '25', 'Cardiology'),
    createDoc('02', 'Dr. Robert King', 'Cardiologist', '6', '10', '04', '40', 'Cardiology'),
  ]},
  { specialty: 'Neurology', doctors: [
    createDoc('06', 'Dr. Emily Clark', 'Neurologist', '3', '10', '08', '70', 'Neurology'),
    createDoc('22', 'Dr. Xavier Brown', 'Neurosurgeon', '1', '45', '08B', '150', 'Neurology'),
  ]},
  { specialty: 'Orthopedics', doctors: [
    createDoc('07', 'Dr. Michael Brown', 'Orthopedic Surgeon', '9', '30', '09', '80', 'Orthopedics'),
  ]},
  { specialty: 'Pediatrics', doctors: [
    createDoc('05', 'Dr. Peter Pan', 'Pediatrician', '4', '5', '07', '30', 'Pediatrics'),
    createDoc('23', 'Dr. Wendy Darling', 'Pediatric Specialist', '2', '15', '07B', '35', 'Pediatrics'),
  ]},
  { specialty: 'OB-GYN', doctors: [
    createDoc('09', 'Dr. Maria Garcia', 'Obstetrician', '7', '40', '10', '55', 'OB-GYN'),
  ]},
  { specialty: 'Dermatology', doctors: [
    createDoc('03', 'Dr. Alex Smith', 'Dermatologist', '8', '15', '05', '50', 'Dermatology'),
    createDoc('04', 'Dr. Sarah Lee', 'Cosmetic Dermatologist', '5', '20', '06', '60', 'Dermatology'),
  ]},
  { specialty: 'ENT', doctors: [
    createDoc('10', 'Dr. David Chen', 'Otolaryngologist', '2', '15', '11', '45', 'ENT'),
  ]},
  { specialty: 'Ophthalmology', doctors: [
    createDoc('11', 'Dr. Linda Wright', 'Eye Surgeon', '10', '20', '12', '50', 'Ophthalmology'),
  ]},
  { specialty: 'Gastroenterology', doctors: [
    createDoc('12', 'Dr. Steven Ross', 'Gastroenterologist', '4', '35', '14', '65', 'Gastroenterology'),
  ]},
  { specialty: 'Urology', doctors: [
    createDoc('13', 'Dr. Kevin Hart', 'Urologist', '1', '10', '15', '75', 'Urology'),
  ]},
  { specialty: 'General Surgery', doctors: [
    createDoc('14', 'Dr. Bruce Banner', 'Trauma Surgeon', '0', '0', 'S-1', '120', 'General Surgery'),
  ]},
  { specialty: 'Psychiatry', doctors: [
    createDoc('15', 'Dr. Harleen Quinzel', 'Psychiatrist', '2', '50', '20', '90', 'Psychiatry'),
  ]},
  { specialty: 'Oncology', doctors: [
    createDoc('16', 'Dr. Victor Stone', 'Oncologist', '5', '45', '22', '150', 'Oncology'),
  ]},
  { specialty: 'Endocrinology', doctors: [
    createDoc('17', 'Dr. Susan Storm', 'Endocrinologist', '3', '30', '25', '$65', 'Endocrinology'),
  ]},
  { specialty: 'Dental', doctors: [
    createDoc('18', 'Dr. Tony Stark', 'Orthodontist', '6', '15', 'D-1', '45', 'Dental'),
    createDoc('24', 'Dr. Pepper Potts', 'Dental Surgeon', '3', '20', 'D-2', '55', 'Dental'),
  ]},
  { specialty: 'Pulmonology', doctors: [
    createDoc('19', 'Dr. Barry Allen', 'Pulmonologist', '8', '10', '28', '55', 'Pulmonology'),
  ]},
  { specialty: 'Nephrology', doctors: [
    createDoc('20', 'Dr. Arthur Curry', 'Nephrologist', '4', '25', '30', '70', 'Nephrology'),
    createDoc('25', 'Dr. Mera Ocean', 'Kidney Specialist', '2', '15', '31', '75', 'Nephrology'),
  ]},
];

export const allDoctors = doctorGroups.flatMap((g) => g.doctors);

const queueCount = (d: Doctor) => parseInt(d.activeQueueCount.split(' ')[0] || '0', 10) || 0;

export function leastBusyDoctor(doctors: Doctor[]): Doctor | undefined {
  return [...doctors].sort((a, b) => queueCount(a) - queueCount(b))[0];
}

/* ---------------------------- Lab tests ---------------------------- */

const labStep = (name: string, description: string, estimatedWait: string, price: number, location: string, requirements: string[]): ClinicStep => ({
  id: clock('lab'), type: 'labTest', name, description, estimatedWait, price, location, requirements, status: 'pending',
});

export const labTests: ClinicStep[] = [
  labStep('Complete Blood Count (CBC)',
    'A foundational diagnostic screening that evaluates your overall health and detects a wide range of disorders, including anemia, infection, and leukemia by measuring red cells, white cells, and platelets.',
    '~10 min', 25, 'Phlebotomy Lab - Level 1', ['Fasting 8-10 hours recommended', 'Hydrate well with water']),
  labStep('Lipid Panel',
    'A comprehensive blood chemistry test used to measure the amount of cholesterol and triglycerides in your blood. This is essential for assessing your risk of developing cardiovascular disease or stroke.',
    '~12 min', 40, 'Main Lab - Level 1', ['Strict 12-hour fasting required', 'No alcohol 24 hours prior']),
  labStep('HbA1c (Glycated Hemoglobin)',
    'A critical test for diabetes management that provides your average levels of blood glucose over the past 3 months. It is the primary tool for monitoring blood sugar control in diabetic patients.',
    '~8 min', 35, 'Rapid Testing Wing', ['No fasting required', 'Can be taken any time of day']),
  labStep('Urinalysis (Full Report)',
    'A detailed physical, chemical, and microscopic examination of urine. It is used to detect and manage a wide range of disorders, such as urinary tract infections, kidney disease, and liver problems.',
    '~5 min', 15, 'Specimen Collection Point', ['Mid-stream clean catch sample required', 'Sterile container provided at desk']),
  labStep('Thyroid Function Test (TFT)',
    'A series of blood tests (TSH, Free T3, Free T4) used to check how well the thyroid gland is working and to help diagnose hyperthyroidism or hypothyroidism.',
    '~10 min', 45, 'Endocrine Lab - Level 2', ['Best performed in the morning', 'Note current thyroid medications']),
  labStep('Liver Function Test (LFT)',
    'A group of blood tests that measure certain enzymes, proteins, and substances produced by the liver. It helps check liver health and screen for infections like hepatitis or cirrhosis.',
    '~15 min', 50, 'Main Lab - Level 1', ['Fasting 8-12 hours required', 'Avoid fatty meals night before']),
  labStep('Kidney Function Test (KFT)',
    'Tests including Serum Creatinine and Blood Urea Nitrogen (BUN) to evaluate how efficiently your kidneys are filtering waste products from your blood.',
    '~12 min', 48, 'Main Lab - Level 1', ['Avoid high-protein meals 24 hours prior', 'Hydration is key']),
  labStep('Vitamin D (25-Hydroxy)',
    'Measures the level of Vitamin D in your blood to ensure bone health and immune system function. Essential for patients experiencing chronic fatigue or bone pain.',
    '~10 min', 65, 'Nutrition Lab - Wing B', ['No specific fasting required', 'Avoid biotin supplements 48h prior']),
  labStep('Electrolyte Panel',
    'Measures the levels of main electrolytes in the body—sodium, potassium, chloride, and bicarbonate—which are vital for nerve and muscle function and acid-base balance.',
    '~8 min', 30, 'Rapid Testing Wing', ['Inform staff of any diuretic use']),
  labStep('Prostate-Specific Antigen (PSA)',
    'A screening test for men that measures the level of PSA in the blood. Elevated levels may indicate inflammation of the prostate or potentially early-stage prostate cancer.',
    '~10 min', 55, 'Urology Lab - Level 3', ['Avoid cycling or ejaculation 48 hours prior']),
];

/* --------------------------- Imaging tests -------------------------- */

const imagingStep = (name: string, description: string, estimatedWait: string, price: number, location: string, requirements: string[]): ClinicStep => ({
  id: clock('img'), type: 'imaging', name, description, estimatedWait, price, location, requirements, status: 'pending',
});

export const imagingTests: ClinicStep[] = [
  imagingStep('Chest X-Ray (PA & Lateral)',
    'A quick, non-invasive imaging test that creates pictures of the structures inside your chest, such as your heart, lungs, and blood vessels to identify pneumonia, heart failure, or lung cancer.',
    '~15 min', 60, 'Radiology - Level 1, Wing A', ['Remove all jewelry and metal objects', 'Wear loose-fitting clothing or use provided gown']),
  imagingStep('CT Scan - Abdomen & Pelvis',
    'Uses a series of X-rays and computer processing to create cross-sectional images (slices) of the bones, blood vessels, and soft tissues inside your abdomen and pelvis.',
    '~45 min', 250, 'Advanced Imaging - Level 1', ['Fasting 4 hours prior', 'May require oral or IV contrast', 'Inform staff of kidney issues or allergies']),
  imagingStep('MRI Brain (Non-Contrast)',
    'Utilizes powerful magnets and radio waves to produce detailed images of the brain and brain stem. Essential for detecting tumors, strokes, and neurological disorders.',
    '~60 min', 450, 'MRI Suite - Basement Level', ['Strictly no metal implants or pacemakers', 'Notify staff of claustrophobia', 'Earplugs provided for noise']),
  imagingStep('Ultrasound - Whole Abdomen',
    'A safe and painless test that uses sound waves to produce images of the organs in the abdomen, including the liver, gallbladder, spleen, pancreas, and kidneys.',
    '~20 min', 85, 'Sonography Room - Wing B', ['Full bladder required (drink 1L water 1h before)', 'Fasting 6-8 hours for gallbladder visualization']),
  imagingStep('Digital Mammography',
    'A specialized medical imaging that uses a low-dose x-ray system to see inside the breasts. It aids in the early detection and diagnosis of breast diseases in women.',
    '~25 min', 120, "Women's Wellness Center - Level 2", ['Do not use deodorant, powder, or lotions under arms', 'Best scheduled week after period']),
  imagingStep('DXA Bone Density Scan',
    'An enhanced form of x-ray technology that is used to measure bone loss. It is the established standard for measuring bone mineral density (BMD) and assessing osteoporosis risk.',
    '~15 min', 110, 'Radiology - Level 1, Wing A', ['No calcium supplements 24 hours prior', 'Wear clothing without metal zippers/buttons']),
  imagingStep('CT Coronary Angiography',
    'An imaging test that looks at the arteries that supply blood to your heart. It is used to check for narrowed or blocked arteries (coronary artery disease).',
    '~90 min', 550, 'Cardiac Imaging Center - Level 3', ['Fast for 4 hours', 'No caffeine 24h prior', 'Check heart rate/beta-blocker protocol']),
  imagingStep('X-Ray - Knee (Left/Right)',
    'Commonly used to look for fractures, joint dislocations, or the extent of arthritis in the knee joint. It provides a clear view of the femur, tibia, and patella.',
    '~10 min', 50, 'Radiology - Level 1, Wing A', ['Remove any metal from lower body', 'Shorts are recommended']),
  imagingStep('MRI Spine (Lumbar)',
    'Produces detailed images of the lower back (lumbar spine). It is often used to diagnose herniated discs, spinal stenosis, or lower back pain origins.',
    '~45 min', 400, 'MRI Suite - Basement Level', ['Must lie still for 30-45 minutes', 'Inform staff of any metal fragments in body']),
  imagingStep('CT Head (Emergency/Trauma)',
    'A rapid diagnostic tool used to visualize the skull and brain. Highly effective for detecting acute bleeding, skull fractures, or brain injury following an accident.',
    '~10 min', 180, 'Emergency Radiology - ER Entrance', ['Priority given to trauma patients', 'Minimal preparation required']),
];

/* Image mapping, mirrors TestDataset.imageName(for:) */
const testNames = [...labTests.map((t) => t.name), ...imagingTests.map((t) => t.name)];
export function testImage(name?: string): string {
  const idx = testNames.findIndex((n) => n === name);
  return idx >= 0 ? `/assets/tests/test${idx + 1}.jpg` : '/assets/tests/testPlaceholder.png';
}

/* ---------------------------- Categories ---------------------------- */

export interface CategoryItem { id: string; title: string; icon: string }

const cat = (title: string, icon: string): CategoryItem => ({ id: `cat-${title}`, title, icon });

export const doctorCategories: CategoryItem[] = [
  cat('General', 'GeneralIcon'), cat('Cardiology', 'CardiologyIcon'), cat('Neurology', 'NeurologyIcon'),
  cat('Orthopedics', 'OrthoIcon'), cat('Pediatrics', 'PediatricsIcon'), cat('OB-GYN', 'OBGYNIcon'),
  cat('Dermatology', 'DermatologyIcon'), cat('ENT', 'ENTIcon'), cat('Ophthalmology', 'OphthalmologyIcon'),
  cat('Gastroenterology', 'GastroIcon'), cat('Urology', 'UrologyIcon'), cat('General Surgery', 'GenSurgeryIcon'),
  cat('Psychiatry', 'PsychiatryIcon'), cat('Oncology', 'OncologyIcon'), cat('Endocrinology', 'EndocrinologyIcon'),
  cat('Dental', 'DentalIcon'), cat('Pulmonology', 'PulmonologyIcon'), cat('Nephrology', 'NephrologyIcon'),
].map((c) => ({ ...c, icon: `/assets/categories/${c.icon}.png` }));

export const labCategories: CategoryItem[] = [
  cat('Clin Biochem', 'GeneralIcon'), cat('Blood Tests', 'blood-test'), cat('Microbiology', 'microbiology'),
  cat('Serology', 'Serology'), cat('Urine Analysis', 'urine-test'), cat('Hormone Tests', 'Hormone'),
  cat('Genetic Tests', 'Genetic'), cat('Special Tests', 'specialtest2'),
].map((c) => ({ ...c, icon: `/assets/categories/${c.icon}.png` }));

export const imagingCategories: CategoryItem[] = [
  cat('X-Ray', 'x-ray2'), cat('CT Scan', 'ct_scan'), cat('MRI', 'mri'), cat('Ultrasound', 'Ultrasound'),
  cat('Mammography', 'mammography'), cat('Bone Density', 'bone_density'), cat('Cardiac Imaging', 'body-scan'),
  cat('Emergency', 'siren'),
].map((c) => ({ ...c, icon: `/assets/categories/${c.icon}.png` }));

/* ----------------------------- Symptoms ----------------------------- */

export interface Symptom { id: string; key: string; label: string; specialty: string }

export const symptoms: Symptom[] = [
  { key: 'fever', label: 'Fever', specialty: 'General' },
  { key: 'headache', label: 'Headache', specialty: 'Neurology' },
  { key: 'cough', label: 'Cough / Cold', specialty: 'Pulmonology' },
  { key: 'stomach', label: 'Stomach Pain', specialty: 'Gastroenterology' },
  { key: 'skin', label: 'Skin Rash / Allergy', specialty: 'Dermatology' },
  { key: 'body', label: 'Body Pain / Joint Pain', specialty: 'Orthopedics' },
  { key: 'vomiting', label: 'Vomiting / Diarrhea', specialty: 'Gastroenterology' },
  { key: 'ear', label: 'Ear Pain', specialty: 'ENT' },
  { key: 'eye', label: 'Eye Problem', specialty: 'Ophthalmology' },
  { key: 'child', label: 'Child Illness', specialty: 'Pediatrics' },
  { key: 'chest', label: 'Chest Pain', specialty: 'Cardiology' },
  { key: 'general', label: 'General Check-up', specialty: 'General' },
].map((s) => ({ ...s, id: `sym-${s.key}` }));

/* --------------------- Test recommendation engine -------------------- */

export function recommendedTestsForSpecialty(specialty: string): ClinicStep[] {
  const out: ClinicStep[] = [];
  const lab = (needle: string) => labTests.find((t) => t.name.includes(needle));
  const img = (needle: string) => imagingTests.find((t) => t.name.includes(needle));
  switch (specialty.toLowerCase()) {
    case 'pediatrician':
    case 'pediatric specialist':
      if (lab('CBC')) out.push(lab('CBC')!);
      if (lab('Urinalysis')) out.push(lab('Urinalysis')!);
      break;
    case 'cardiologist':
      if (lab('Lipid')) out.push(lab('Lipid')!);
      if (img('CT Coronary')) out.push(img('CT Coronary')!);
      break;
    case 'neurologist':
    case 'neurosurgeon':
      if (img('MRI Brain')) out.push(img('MRI Brain')!);
      break;
    case 'orthopedic surgeon':
      if (img('X-Ray - Knee')) out.push(img('X-Ray - Knee')!);
      if (img('DXA Bone Density')) out.push(img('DXA Bone Density')!);
      break;
    case 'gastroenterologist':
      if (lab('Liver')) out.push(lab('Liver')!);
      if (img('Abdomen')) out.push(img('Abdomen')!);
      break;
    case 'urologist':
    case 'kidney specialist':
      if (lab('Kidney')) out.push(lab('Kidney')!);
      if (lab('Urinalysis')) out.push(lab('Urinalysis')!);
      break;
    case 'obstetrician':
      if (img('Mammography')) out.push(img('Mammography')!);
      break;
    default:
      break;
  }
  return out;
}

export function recommendedTestsForSymptom(symptomKey: string): ClinicStep[] {
  const out: ClinicStep[] = [];
  const lab = (needle: string) => labTests.find((t) => t.name.includes(needle));
  const img = (needle: string) => imagingTests.find((t) => t.name.includes(needle));
  switch (symptomKey.toLowerCase()) {
    case 'eye':
      if (lab('CBC')) out.push(lab('CBC')!);
      if (img('MRI Brain')) out.push(img('MRI Brain')!);
      break;
    case 'chest':
      if (lab('Lipid')) out.push(lab('Lipid')!);
      if (img('Chest X-Ray')) out.push(img('Chest X-Ray')!);
      break;
    case 'fever':
      if (lab('CBC')) out.push(lab('CBC')!);
      break;
    case 'stomach':
      if (lab('Liver')) out.push(lab('Liver')!);
      if (img('Abdomen')) out.push(img('Abdomen')!);
      break;
    default:
      break;
  }
  return out;
}

/* ----------------------------- Pharmacy ----------------------------- */

export interface Drug {
  id: string; name: string; subtitle: string; price: number; image: string;
}

export const drugs: Drug[] = [
  { name: 'PANADOL TABLETS', subtitle: '20pcs', price: 10.99, image: 'PANADOL' },
  { name: 'Condoms', subtitle: '20pcs', price: 10.99, image: 'Condoms' },
  { name: 'Postinor', subtitle: '200mg', price: 10.99, image: 'postinor' },
  { name: 'Lipitor Atorvastatin', subtitle: '20pcs', price: 10.99, image: 'Lipitor' },
  { name: 'Ibuprofen', subtitle: '200mg', price: 10.99, image: 'Ibuprofen' },
  { name: 'Levothyroxine', subtitle: '20pcs', price: 10.99, image: 'levothyroxine' },
  { name: 'Amoxicillin', subtitle: '500mg · 30 caps', price: 14.99, image: 'Amoxicillin' },
  { name: 'Vitamin D3', subtitle: '1000IU · 60 tabs', price: 8.99, image: 'VitaminD3' },
].map((d, i) => ({ ...d, id: `drug-${i}`, image: `/assets/drugs/${d.image}.png` }));

/* --------------------------- Clinic floor map ------------------------ */

export interface ClinicRoom {
  id: string; name: string; number: string; icon: string; accent: string;
  rect: { x: number; y: number; w: number; h: number };
  walkMins: number; isEntrance: boolean;
}

const room = (
  name: string, number: string, icon: string, accent: string,
  x: number, y: number, w: number, h: number, walkMins: number, isEntrance = false
): ClinicRoom => ({ id: `room-${number}`, name, number, icon, accent, rect: { x, y, w, h }, walkMins, isEntrance });

export const groundRooms: ClinicRoom[] = [
  room('Entrance', 'G00', 'door.left.hand.open', '#0DC8A4', 0.30, 0.03, 0.40, 0.10, 0, true),
  room('Registration', 'G01', 'clipboard.fill', '#4A90E2', 0.03, 0.17, 0.43, 0.16, 1),
  room('Waiting', 'G02', 'person.3.fill', '#27AE60', 0.54, 0.17, 0.43, 0.16, 1),
  room('Consultation', 'G03', 'stethoscope', '#9B59B6', 0.03, 0.38, 0.43, 0.16, 2),
  room('Pharmacy', 'G04', 'pills.fill', '#E67E22', 0.54, 0.38, 0.43, 0.16, 2),
  room('Payment', 'G05', 'creditcard.fill', '#F1C40F', 0.03, 0.59, 0.28, 0.14, 3),
  room('Restrooms', 'G06', 'figure.stand', '#95A5A6', 0.36, 0.59, 0.28, 0.14, 1),
  room('Elevator', 'G07', 'arrow.up.arrow.down.circle.fill', '#1A2E44', 0.69, 0.59, 0.28, 0.14, 2),
];

export const upperRooms: ClinicRoom[] = [
  room('Elevator', '2-LFT', 'arrow.up.arrow.down.circle.fill', '#1A2E44', 0.36, 0.03, 0.28, 0.10, 0, true),
  room('Specialist', '201', 'cross.case.fill', '#E74C3C', 0.03, 0.17, 0.43, 0.16, 1),
  room('Cardiology', '202', 'heart.fill', '#C0392B', 0.54, 0.17, 0.43, 0.16, 2),
  room('Laboratory', '203', 'testtube.2', '#8E44AD', 0.03, 0.38, 0.43, 0.16, 3),
  room('Radiology', '204', 'xmark.shield.fill', '#2980B9', 0.54, 0.38, 0.43, 0.16, 3),
  room('Blood Test', '205', 'drop.fill', '#E74C3C', 0.03, 0.59, 0.28, 0.14, 4),
  room('Pathology', '206', 'cross.vial.fill', '#6C3483', 0.36, 0.59, 0.28, 0.14, 4),
  room('Restrooms', '207', 'figure.stand', '#95A5A6', 0.69, 0.59, 0.28, 0.14, 2),
];

export function roomsForFloor(floor: 1 | 2) {
  return floor === 1 ? groundRooms : upperRooms;
}

export function roomByName(name: string): ClinicRoom | undefined {
  return [...upperRooms, ...groundRooms].find((r) => r.name === name);
}

/* ------------------------- History / seed data ----------------------- */

export interface AppointmentRecord {
  id: string;
  date: Date;
  patientName: string;
  age: number;
  gender: string;
  doctor: Doctor;
  totalFee: number;
  status: 'upcoming' | 'completed' | 'refunded';
  tests: string[];
  isRefunded?: boolean;
}

const daysFromNow = (n: number) => {
  const d = new Date();
  d.setDate(d.getDate() + n);
  return d;
};

export const seedAppointments: AppointmentRecord[] = [
  {
    id: clock('appt'), date: new Date(), patientName: 'John Doe', age: 32, gender: 'Male',
    doctor: allDoctors[0], totalFee: 21.0, status: 'upcoming',
    tests: ['Complete Blood Count (CBC)', 'Lipid Panel', 'Chest X-Ray (PA & Lateral)'],
  },
  {
    id: clock('appt'), date: daysFromNow(1), patientName: 'John Doe', age: 32, gender: 'Male',
    doctor: allDoctors[3], totalFee: 76.0, status: 'upcoming', tests: [],
  },
  {
    id: clock('appt'), date: daysFromNow(3), patientName: 'John Doe', age: 32, gender: 'Male',
    doctor: allDoctors[6], totalFee: 91.0, status: 'upcoming', tests: [],
  },
  {
    id: clock('appt'), date: daysFromNow(-3), patientName: 'John Doe', age: 32, gender: 'Male',
    doctor: allDoctors[8], totalFee: 121.0, status: 'completed',
    tests: ['Complete Blood Count (CBC)', 'Chest X-Ray (PA & Lateral)'],
  },
  {
    id: clock('appt'), date: daysFromNow(-7), patientName: 'John Doe', age: 32, gender: 'Male',
    doctor: allDoctors[10], totalFee: 96.0, status: 'completed',
    tests: ['Urine Full Report', 'Ultrasound - Whole Abdomen'],
  },
  {
    id: clock('appt'), date: daysFromNow(-14), patientName: 'John Doe', age: 32, gender: 'Male',
    doctor: allDoctors[2], totalFee: 111.0, status: 'refunded', tests: [], isRefunded: true,
  },
];

export interface TransactionRecord {
  id: string; title: string; doctor: string; date: string; total: string;
  status: 'Completed' | 'Refunded';
}

export const seedTransactions: TransactionRecord[] = [
  { id: clock('tx'), title: 'Doctor/Consultant Channeling', doctor: 'Dr. Marcus Horizon', date: '2026/02/04 at 01:55PM', total: '$60.00', status: 'Completed' },
  { id: clock('tx'), title: 'Doctor/Consultant Channeling', doctor: 'Dr. Marcus Horizon', date: '2026/02/04 at 01:55PM', total: '$60.00', status: 'Refunded' },
  { id: clock('tx'), title: 'Laboratory Services', doctor: 'Dr. Jane Doe', date: '2026/01/22 at 09:12AM', total: '$76.00', status: 'Completed' },
];

export interface TestHistoryRecord {
  id: string; testName: string; doctor: string; date: string; total: string;
  status: 'Completed' | 'Rescheduled' | 'Pending';
}

export const seedTestHistory: TestHistoryRecord[] = [
  { id: clock('th'), testName: 'Complete Blood Count (CBC)', doctor: 'Dr. Marcus Horizon', date: '2026/02/04 at 01:55PM', total: '$60.00', status: 'Completed' },
  { id: clock('th'), testName: 'Fasting Blood Glucose', doctor: 'Dr. Marcus Horizon', date: '2026/02/04 at 01:55PM', total: '$60.00', status: 'Rescheduled' },
  { id: clock('th'), testName: 'Lipid Panel', doctor: 'Dr. Sarah Nolan', date: '2026/02/20 at 10:30AM', total: '$85.00', status: 'Completed' },
  { id: clock('th'), testName: 'Kidney Function Test', doctor: 'Dr. Sarah Nolan', date: '2026/03/15 at 09:00AM', total: '$120.00', status: 'Pending' },
];

export interface NotificationRecord {
  id: string; title: string; description: string; date: string; time: string;
  avatar: 'person' | 'blood' | 'pills' | 'person2';
}

export const notifications: { section: string; items: NotificationRecord[] }[] = [
  {
    section: 'Today',
    items: [
      { id: clock('n'), title: 'Scheduled Appointment', description: 'Your channeling appointment with Dr. Jane Doe is confirmed for today at 5:30 PM. Please arrive 15 minutes early.', date: '2026/02/16', time: '12:13PM', avatar: 'person' },
      { id: clock('n'), title: 'Lab Report Ready', description: 'Your Complete Blood Count report is now available under My Tests. A doctor review has been requested.', date: '2026/02/16', time: '12:13PM', avatar: 'blood' },
    ],
  },
  {
    section: 'Yesterday',
    items: [
      { id: clock('n'), title: 'Prescription Dispensed', description: 'Your prescription has been prepared at the pharmacy counter. Present your queue QR to collect.', date: '2026/02/16', time: '12:13PM', avatar: 'pills' },
    ],
  },
  {
    section: 'Oct 17',
    items: [
      { id: clock('n'), title: 'Queue Update', description: 'Your position in the consultation queue has advanced. You are now 2 patients away.', date: '2026/02/16', time: '12:13PM', avatar: 'person2' },
    ],
  },
];

export const paymentMethods = [
  { id: 'apple', name: 'Apple Pay', icon: '/assets/pay/apple.png' },
  { id: 'visa', name: 'Visa', icon: '/assets/pay/visa.png' },
  { id: 'mastercard', name: 'MasterCard', icon: '/assets/pay/mastercard.png' },
  { id: 'paypal', name: 'PayPal', icon: '/assets/pay/paypal.png' },
];

export const ADMIN_FEE = 1.0;
export const DISCOUNT = 0.0;

export const money = (n: number) => `$${n.toFixed(2)}`;
export const priceOf = (price?: string) =>
  parseFloat((price || '0').replace(/[^0-9.]/g, '')) || 0;
