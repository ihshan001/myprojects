//
//  TestDataset.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-12.
//

import Foundation

struct TestItem {
    let id: Int
    let name: String
}

struct TestDataset {
    static let tests: [TestItem] = {
        var allTests = [TestItem]()
        var counter = 1

        let labNames = [
            "Complete Blood Count (CBC)",
            "Lipid Panel",
            "HbA1c (Glycated Hemoglobin)",
            "Urinalysis (Full Report)",
            "Thyroid Function Test (TFT)",
            "Liver Function Test (LFT)",
            "Kidney Function Test (KFT)",
            "Vitamin D (25-Hydroxy)",
            "Electrolyte Panel",
            "Prostate-Specific Antigen (PSA)"
        ]

        for name in labNames {
            allTests.append(TestItem(id: counter, name: name))
            counter += 1
        }

        let imagingNames = [
            "Chest X-Ray (PA & Lateral)",
            "CT Scan - Abdomen & Pelvis",
            "MRI Brain (Non-Contrast)",
            "Ultrasound - Whole Abdomen",
            "Digital Mammography",
            "DXA Bone Density Scan",
            "CT Coronary Angiography",
            "X-Ray - Knee (Left/Right)",
            "MRI Spine (Lumbar)",
            "CT Head (Emergency/Trauma)"
        ]

        for name in imagingNames {
            allTests.append(TestItem(id: counter, name: name))
            counter += 1
        }

        return allTests
    }()

    static func imageName(for testName: String) -> String {
        if let item = tests.first(where: { $0.name == testName }) {
            return "test\(item.id)"
        }
        return "test0"
    }
}
