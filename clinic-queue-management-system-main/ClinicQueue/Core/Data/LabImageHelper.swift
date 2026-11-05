//
//  LabImageHelper.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-12.
//

struct LabTestDataset {
    static let tests: [(id: Int, name: String)] = [
        (id: 1, name: "Complete Blood Count (CBC)"),
        (id: 2, name: "Lipid Panel"),
        (id: 3, name: "HbA1c (Glycated Hemoglobin)"),
        (id: 4, name: "Urinalysis (Full Report)"),
        (id: 5, name: "Thyroid Function Test (TFT)"),
        (id: 6, name: "Liver Function Test (LFT)"),
        (id: 7, name: "Kidney Function Test (KFT)"),
        (id: 8, name: "Vitamin D (25-Hydroxy)"),
        (id: 9, name: "Electrolyte Panel"),
        (id: 10, name: "Prostate-Specific Antigen (PSA)")
    ]
}

struct LabIconHelper {
    static func iconName(for testName: String) -> String {
        if let test = LabTestDataset.tests.first(where: { $0.name == testName }) {
            return "lab\(test.id)"
        }
        return "default_lab_icon"
    }
}
