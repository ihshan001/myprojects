import SwiftUI

struct DoctorListView: View {
    let specialties = [
        "General Physician",
        "Cardiologist",
        "Dermatologist",
        "Pediatrician",
        "Gynecologist",
        "Orthopedic Surgeon",
        "Neurologist",
        "Psychiatrist",
//        "ENT Specialist",
//        "Ophthalmologist",
//        "Urologist",
//        "Gastroenterologist",
//        "Endocrinologist",
//        "Pulmonologist",
//        "Oncologist"
    ]
    
    
    let doctorCards: [DoctorCardData] = [
        DoctorCardData(
            icon: "SearchIcon",
            iconSize: 32,
            title: "Dr. Marcus Horizon",
            subTitle: "Cardiologist",
            description1: "12 patients in queue",
            description2: "",
            label1: "Estimated wait: ",
            label1Text: "~25 min",
            label2: "Location: ",
            label2Text: "Room 02 – Consultation Wing",
            buttonText: "Fee $25"
        ),
        DoctorCardData(
            icon: "SearchIcon",
            iconSize: 32,
            title: "Dr. Emily Carter",
            subTitle: "Dermatologist",
            description1: "6 patients in queue",
            description2: "",
            label1: "Estimated wait: ",
            label1Text: "~15 min",
            label2: "Location: ",
            label2Text: "Room 05 – Skin Care Wing",
            buttonText: "Fee $30"
        ),
        DoctorCardData(
            icon: "SearchIcon",
            iconSize: 32,
            title: "Dr. James Wilson",
            subTitle: "Neurologist",
            description1: "9 patients in queue",
            description2: "",
            label1: "Estimated wait: ",
            label1Text: "~40 min",
            label2: "Location: ",
            label2Text: "Room 08 – Neuro Wing",
            buttonText: "Fee $45"
        )
    ]
    
    
    let columns = [
        GridItem(.flexible(), spacing: 12),
        GridItem(.flexible(), spacing: 12),
        GridItem(.flexible(), spacing: 12),
        GridItem(.flexible(), spacing: 12)
    ]
    
    
    @State private var selectedDoctor: DoctorCardData?
    @State private var navigate = false
    
    var body: some View {
      
        ZStack(alignment: .top) {
            ScrollView {
                VStack(alignment: .center, spacing: 20){
                    
//                    IconInputField(
//                        placeholder: "Find a Doctor",
//                        defaultValue: "",
//                        iconName: "SearchIcon",
//                        action: { print("Input changed") }
//                    )
                }
                
                Text("Category")
                    .font(.app(.heading))
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding(.top, 32)
              
                LazyVGrid(columns: columns, spacing: 8) {
                    ForEach(specialties, id: \.self) { specialty in
                        CategoryButton(title: specialty, icon: "SearchIcon", iconWidth: 38) {
                            print("category clicked: \(specialty)")
                        }
                        .frame(maxHeight: .infinity, alignment: .top)
                    }
                }.padding(.top, 20)
                
                
                Text("Available  Doctors")
                    .font(.app(.heading))
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .padding(.top, 32)
                
                VStack(spacing: 12) {
                    ForEach(doctorCards) { card in
                        DisplayCard(props: card) {
                            selectedDoctor = card
                            navigate = true
                        }
                    }
                }.navigationDestination(isPresented: $navigate) {
                    if let doctor = selectedDoctor {
                        
                        AppointmentView(doctor: DoctorCardData(
                            icon: doctor.icon,
                            iconSize: doctor.iconSize,
                            title: doctor.title,
                            subTitle: doctor.subTitle,
                            description1: doctor.description1,
                            description2: doctor.description2,
                            label1: doctor.label1,
                            label1Text: doctor.label1Text,
                            label2: doctor.label2,
                            label2Text: doctor.label2Text,
                            buttonText: ""
                        ))
                    }
                }
                .padding(.top, 20)
                
                
                
          }
            
        }
        .safeAreaInset(edge: .top) {
            HeaderSection(title: "Doctors")
        }
    }
}

struct DoctorListView_Previews: PreviewProvider {
    static var previews: some View {
        DoctorListView()
    }
}
