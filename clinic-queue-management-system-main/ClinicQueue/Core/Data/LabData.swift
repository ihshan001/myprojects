//
//  LabData.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-09.
//

import SwiftUI

struct LabData {
    static let labTest: [ClinicStep] = [
        ClinicStep(
            type: .labTest,
            name: "Complete Blood Count (CBC)",
            description: "A foundational diagnostic screening that evaluates your overall health and detects a wide range of disorders, including anemia, infection, and leukemia by measuring red cells, white cells, and platelets.",
            estimatedWait: "~10 min",
            price: 25,
            location: "Phlebotomy Lab - Level 1",
            requirements: ["Fasting 8-10 hours recommended", "Hydrate well with water"], symptomKeys: []
        ),
        ClinicStep(
            type: .labTest,
            name: "Lipid Panel",
            description: "A comprehensive blood chemistry test used to measure the amount of cholesterol and triglycerides in your blood. This is essential for assessing your risk of developing cardiovascular disease or stroke.",
            estimatedWait: "~12 min",
            price: 40,
            location: "Main Lab - Level 1",
            requirements: ["Strict 12-hour fasting required", "No alcohol 24 hours prior"], symptomKeys: []
        ),
        ClinicStep(
            type: .labTest,
            name: "HbA1c (Glycated Hemoglobin)",
            description: "A critical test for diabetes management that provides your average levels of blood glucose over the past 3 months. It is the primary tool for monitoring blood sugar control in diabetic patients.",
            estimatedWait: "~8 min",
            price: 35,
            location: "Rapid Testing Wing",
            requirements: ["No fasting required", "Can be taken any time of day"], symptomKeys: []
        ),
        ClinicStep(
            type: .labTest,
            name: "Urinalysis (Full Report)",
            description: "A detailed physical, chemical, and microscopic examination of urine. It is used to detect and manage a wide range of disorders, such as urinary tract infections, kidney disease, and liver problems.",
            estimatedWait: "~5 min",
            price: 15,
            location: "Specimen Collection Point",
            requirements: ["Mid-stream clean catch sample required", "Sterile container provided at desk"], symptomKeys: []
        ),
        ClinicStep(
            type: .labTest,
            name: "Thyroid Function Test (TFT)",
            description: "A series of blood tests (TSH, Free T3, Free T4) used to check how well the thyroid gland is working and to help diagnose hyperthyroidism or hypothyroidism.",
            estimatedWait: "~10 min",
            price: 45,
            location: "Endocrine Lab - Level 2",
            requirements: ["Best performed in the morning", "Note current thyroid medications"], symptomKeys: []
        ),
        ClinicStep(
            type: .labTest,
            name: "Liver Function Test (LFT)",
            description: "A group of blood tests that measure certain enzymes, proteins, and substances produced by the liver. It helps check liver health and screen for infections like hepatitis or cirrhosis.",
            estimatedWait: "~15 min",
            price: 50,
            location: "Main Lab - Level 1",
            requirements: ["Fasting 8-12 hours required", "Avoid fatty meals night before"], symptomKeys: []
        ),
        ClinicStep(
            type: .labTest,
            name: "Kidney Function Test (KFT)",
            description: "Tests including Serum Creatinine and Blood Urea Nitrogen (BUN) to evaluate how efficiently your kidneys are filtering waste products from your blood.",
            estimatedWait: "~12 min",
            price: 48,
            location: "Main Lab - Level 1",
            requirements: ["Avoid high-protein meals 24 hours prior", "Hydration is key"], symptomKeys: []
        ),
        ClinicStep(
            type: .labTest,
            name: "Vitamin D (25-Hydroxy)",
            description: "Measures the level of Vitamin D in your blood to ensure bone health and immune system function. Essential for patients experiencing chronic fatigue or bone pain.",
            estimatedWait: "~10 min",
            price: 65,
            location: "Nutrition Lab - Wing B",
            requirements: ["No specific fasting required", "Avoid biotin supplements 48h prior"], symptomKeys: []
        ),
        ClinicStep(
            type: .labTest,
            name: "Electrolyte Panel",
            description: "Measures the levels of main electrolytes in the body—sodium, potassium, chloride, and bicarbonate—which are vital for nerve and muscle function and acid-base balance.",
            estimatedWait: "~8 min",
            price: 30,
            location: "Rapid Testing Wing",
            requirements: ["Inform staff of any diuretic use"], symptomKeys: []
        ),
        ClinicStep(
            type: .labTest,
            name: "Prostate-Specific Antigen (PSA)",
            description: "A screening test for men that measures the level of PSA in the blood. Elevated levels may indicate inflammation of the prostate or potentially early-stage prostate cancer.",
            estimatedWait: "~10 min",
            price: 55,
            location: "Urology Lab - Level 3",
            requirements: ["Avoid cycling or ejaculation 48 hours prior"], symptomKeys: []
        )
    ]

    // ✅ Maps [ClinicStep] → [LabCardData] so LabDetails can consume it directly
    static var labTests: [LabCardData] {
        labTest.map { step in
            LabCardData(
                icon: "SearchIcon",
                iconSize: 32,
                title: step.name,
                description1: step.description ?? "",
                label1: "Estimated wait: ",
                label1Text: step.estimatedWait ?? "-",
                label2: "Location: ",
                label2Text: step.location ?? "-",
                buttonText: step.price.map { "$\(Int($0))" } ?? "-"
            )
        }
    }
}

