//
//  ImagingData.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-10.
//

import SwiftUI

struct ImagingData {
    static let imagingTests: [ClinicStep] = [
        ClinicStep(
            type: .imaging, 
            name: "Chest X-Ray (PA & Lateral)",
            description: "A quick, non-invasive imaging test that creates pictures of the structures inside your chest, such as your heart, lungs, and blood vessels to identify pneumonia, heart failure, or lung cancer.",
            estimatedWait: "~15 min",
            price: 60,
            location: "Radiology - Level 1, Wing A",
            requirements: ["Remove all jewelry and metal objects", "Wear loose-fitting clothing or use provided gown"]
        ),
        ClinicStep(
            type: .imaging,
            name: "CT Scan - Abdomen & Pelvis",
            description: "Uses a series of X-rays and computer processing to create cross-sectional images (slices) of the bones, blood vessels, and soft tissues inside your abdomen and pelvis.",
            estimatedWait: "~45 min",
            price: 250,
            location: "Advanced Imaging - Level 1",
            requirements: ["Fasting 4 hours prior", "May require oral or IV contrast", "Inform staff of kidney issues or allergies"]
        ),
        ClinicStep(
            type: .imaging,
            name: "MRI Brain (Non-Contrast)",
            description: "Utilizes powerful magnets and radio waves to produce detailed images of the brain and brain stem. Essential for detecting tumors, strokes, and neurological disorders.",
            estimatedWait: "~60 min",
            price: 450,
            location: "MRI Suite - Basement Level",
            requirements: ["Strictly no metal implants or pacemakers", "Notify staff of claustrophobia", "Earplugs provided for noise"]
        ),
        ClinicStep(
            type: .imaging,
            name: "Ultrasound - Whole Abdomen",
            description: "A safe and painless test that uses sound waves to produce images of the organs in the abdomen, including the liver, gallbladder, spleen, pancreas, and kidneys.",
            estimatedWait: "~20 min",
            price: 85,
            location: "Sonography Room - Wing B",
            requirements: ["Full bladder required (drink 1L water 1h before)", "Fasting 6-8 hours for gallbladder visualization"]
        ),
        ClinicStep(
            type: .imaging,
            name: "Digital Mammography",
            description: "A specialized medical imaging that uses a low-dose x-ray system to see inside the breasts. It aids in the early detection and diagnosis of breast diseases in women.",
            estimatedWait: "~25 min",
            price: 120,
            location: "Women's Wellness Center - Level 2",
            requirements: ["Do not use deodorant, powder, or lotions under arms", "Best scheduled week after period"]
        ),
        ClinicStep(
            type: .imaging,
            name: "DXA Bone Density Scan",
            description: "An enhanced form of x-ray technology that is used to measure bone loss. It is the established standard for measuring bone mineral density (BMD) and assessing osteoporosis risk.",
            estimatedWait: "~15 min",
            price: 110,
            location: "Radiology - Level 1, Wing A",
            requirements: ["No calcium supplements 24 hours prior", "Wear clothing without metal zippers/buttons"]
        ),
        ClinicStep(
            type: .imaging,
            name: "CT Coronary Angiography",
            description: "An imaging test that looks at the arteries that supply blood to your heart. It is used to check for narrowed or blocked arteries (coronary artery disease).",
            estimatedWait: "~90 min",
            price: 550,
            location: "Cardiac Imaging Center - Level 3",
            requirements: ["Fast for 4 hours", "No caffeine 24h prior", "Check heart rate/beta-blocker protocol"]
        ),
        ClinicStep(
            type: .imaging,
            name: "X-Ray - Knee (Left/Right)",
            description: "Commonly used to look for fractures, joint dislocations, or the extent of arthritis in the knee joint. It provides a clear view of the femur, tibia, and patella.",
            estimatedWait: "~10 min",
            price: 50,
            location: "Radiology - Level 1, Wing A",
            requirements: ["Remove any metal from lower body", "Shorts are recommended"]
        ),
        ClinicStep(
            type: .imaging,
            name: "MRI Spine (Lumbar)",
            description: "Produces detailed images of the lower back (lumbar spine). It is often used to diagnose herniated discs, spinal stenosis, or lower back pain origins.",
            estimatedWait: "~45 min",
            price: 400,
            location: "MRI Suite - Basement Level",
            requirements: ["Must lie still for 30-45 minutes", "Inform staff of any metal fragments in body"]
        ),
        ClinicStep(
            type: .imaging,
            name: "CT Head (Emergency/Trauma)",
            description: "A rapid diagnostic tool used to visualize the skull and brain. Highly effective for detecting acute bleeding, skull fractures, or brain injury following an accident.",
            estimatedWait: "~10 min",
            price: 180,
            location: "Emergency Radiology - ER Entrance",
            requirements: ["Priority given to trauma patients", "Minimal preparation required"]
        )
    ]
}
